<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * NginxIsolationService — per-account nginx worker isolation.
 *
 * When enabled for an account, the account's domains are served by a dedicated
 * nginx master+worker running as that account (confined to its cgroup slice),
 * listening on a dedicated loopback port (127.0.0.1:<12000+account_id>). The
 * shared front-door nginx keeps terminating TLS/HTTP and reverse-proxies the
 * account's domains to that port.
 *
 * All privileged filesystem/systemd work is delegated to the agent via
 * nginx.worker.* operations.
 */
class NginxIsolationService
{
    public const PORT_BASE = 12000;
    public const PORT_MAX = 65000;

    /**
     * Dedicated loopback port for an account (12000 + account_id). Returns null
     * when the computed port would overflow the valid high-port range.
     */
    public function portFor(int $accountId): ?int
    {
        $port = self::PORT_BASE + $accountId;

        if ($port > self::PORT_MAX) {
            return null;
        }

        return $port;
    }

    public function isEnabled(array $account): bool
    {
        return (bool) ($account['nginx_worker_enabled'] ?? false);
    }

    /**
     * Ensure the account's nginx worker config tree + runtime exist, then start
     * (or restart) the per-account nginx unit. Records state in nginx_workers.
     */
    public function enable(array $account): array
    {
        $port = $this->portFor((int) $account['id']);

        if ($port === null) {
            return ['status' => 'error', 'output' => 'account_id too large for per-account port.'];
        }

        $this->write($account);
        $this->rewriteVhosts($account, $port);

        // Re-run write so the nginx tree is (re)asserted root-owned: conf.d and
        // nginx.conf read-only to the account, only tmp/ + run/ account-owned.
        $this->write($account);

        $start = $this->startWorker($account);

        $status = $start['status'] === 'success' ? 'active' : 'failed';

        $this->record((int) $account['id'], $port, $status);

        return $start;
    }

    /**
     * Stop the per-account nginx unit, restore the original front-door vhosts,
     * and mark it disabled.
     */
    public function disable(array $account): array
    {
        $result = app('provisioning')->execute('nginx.worker.stop', [
            'account' => $account['username'],
        ]);

        $this->restoreVhosts($account);

        // The shared front-door nginx runs httpd_t:s0 and cannot read s0:cN
        // content, so without isolation the account tree must go back to s0
        // (restorecon resets the level, and the type via fcontext). MCS
        // isolation is thus reverted cleanly when a worker is turned off.
        try {
            $home = $account['home_dir'] ?? ('/home/' . $account['username']);
            app('provisioning')->execute('selinux.restorecon', ['path' => $home]);
        } catch (\Throwable $e) {
            app('logger')->warning('Isolation disable relabel failed for ' . $account['username'] . ': ' . $e->getMessage());
        }

        $this->record((int) $account['id'], 0, 'stopped');

        return $result;
    }

    public function status(array $account): string
    {
        $result = app('provisioning')->execute('nginx.worker.status', [
            'account' => $account['username'],
        ]);

        return trim((string) ($result['output'] ?? 'unknown'));
    }

    /**
     * Write the account's nginx master config (nginx.conf + conf.d dir),
     * sized by the account's package (workers + upload cap).
     */
    public function write(array $account): array
    {
        $package = app('db')->selectOne(
            'SELECT `nginx_workers`, `nginx_max_body_mb` FROM `packages` WHERE `id` = ?',
            [(int) ($account['package_id'] ?? 0)]
        );
        $cat = (int) ($account['mcs_category'] ?? 0);
        return app('provisioning')->execute('nginx.worker.write', [
            'account' => $account['username'],
            'workers' => (string) max(1, min(8, (int) ($package['nginx_workers'] ?? 2))),
            'max_body_mb' => (string) max(1, min(2048, (int) ($package['nginx_max_body_mb'] ?? 32))),
            'mcs_category' => ($cat > 0 && $cat <= 1023) ? 'c' . $cat : '',
        ]);
    }

    /**
     * Push one regenerated vhost live, isolation-aware: isolated accounts get
     * the full account rewrite (conf.d + front-door proxies) plus a worker
     * restart so conf.d changes take effect; everyone else gets a direct
     * front-door write. Callers must persist websites.nginx_config first,
     * since the isolated path re-reads stored configs.
     */
    public function pushVhost(array $account, string $domainName, string $nginx): void
    {
        if ($this->isEnabled($account)) {
            $port = $this->portFor((int) $account['id']);
            if ($port !== null) {
                $this->rewriteVhosts($account, $port);
                $this->startWorker($account);
                return;
            }
        }
        app(\Modules\Websites\Services\VhostWriter::class)->write($domainName, $nginx);
    }

    /**
     * Run `nginx -t` against the account worker config. Returns the combined
     * output (success text or the config error to show the customer).
     */
    public function testWorker(array $account): string
    {
        $result = app('provisioning')->execute('nginx.worker.test', [
            'account' => $account['username'],
        ]);
        return trim((string) ($result['output'] ?? ($result['status'] ?? 'unknown')));
    }

    /**
     * Start/restart the account's worker, passing the account's SELinux MCS
     * category so the worker runs httpd_t:s0:cN and can read its own
     * s0:cN-labeled content once MCS is enforced.
     */
    public function startWorker(array $account): array
    {
        $cat = (int) ($account['mcs_category'] ?? 0);
        return app('provisioning')->execute('nginx.worker.start', [
            'account' => $account['username'],
            'mcs_category' => ($cat > 0 && $cat <= 1023) ? 'c' . $cat : '',
        ]);
    }

    /**
     * Save a customer custom-nginx snippet for a website with full rollback:
     * the snippet is denylisted + generated first (throws before anything is
     * persisted), then pushed; isolated workers are config-tested BEFORE
     * restart, and any failure restores the previous DB rows + conf.d without
     * ever restarting the worker on a bad config.
     *
     * @throws \RuntimeException with the nginx error on failure
     */
    public function saveCustomNginx(int $websiteId, string $snippet): void
    {
        $site = app('db')->selectOne('SELECT * FROM `websites` WHERE `id` = ?', [$websiteId]);
        if ($site === null) {
            throw new \RuntimeException('Website not found.');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $site['account_id']]);
        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) $site['domain_id']]);
        if ($account === null || $domain === null) {
            throw new \RuntimeException('Website has no account or domain.');
        }

        $snippet = trim($snippet);
        if (strlen($snippet) > 8192) {
            throw new \RuntimeException('Custom directives exceed 8 KB.');
        }

        $oldSnippet = $site['custom_nginx'] ?? null;
        $oldConfig = $site['nginx_config'] ?? '';

        // Generate first: denylist violations throw before anything persists.
        $candidate = $site;
        $candidate['custom_nginx'] = $snippet !== '' ? $snippet : null;
        $nginx = app(\Modules\Websites\Services\NginxConfigGenerator::class)->generate($candidate, $domain, $account);

        app('db')->update('websites', [
            'custom_nginx' => $snippet !== '' ? $snippet : null,
            'nginx_config' => $nginx,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$websiteId]);

        try {
            if ($this->isEnabled($account)) {
                $port = $this->portFor((int) $account['id']);
                if ($port === null) {
                    throw new \RuntimeException('Account port out of range.');
                }
                $this->rewriteVhosts($account, $port);
                $testOut = $this->testWorker($account);
                if (!str_contains($testOut, 'test is successful') && !str_contains($testOut, 'syntax is ok')) {
                    throw new \RuntimeException('Worker config test failed: ' . $testOut);
                }
                $this->startWorker($account);
            } else {
                app(\Modules\Websites\Services\VhostWriter::class)->write($domain['name'], $nginx);
            }
        } catch (\Throwable $e) {
            // Roll back DB rows, then re-push the previous config so disk
            // matches the DB again. The worker is only restarted on success,
            // so it never ran the bad config.
            app('db')->update('websites', [
                'custom_nginx' => $oldSnippet,
                'nginx_config' => $oldConfig,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$websiteId]);
            try {
                if ($this->isEnabled($account)) {
                    $port = $this->portFor((int) $account['id']);
                    if ($port !== null) {
                        $this->rewriteVhosts($account, $port);
                    }
                } else {
                    app(\Modules\Websites\Services\VhostWriter::class)->write($domain['name'], (string) $oldConfig);
                }
            } catch (\Throwable $ignored) {
            }
            throw new \RuntimeException($e->getMessage());
        }
    }

    /**
     * Re-route the account's existing websites: write a per-account vhost (listen
     * 127.0.0.1:<port>) into the account conf.d, and generate the matching
     * front-door proxy server block so the shared nginx forwards to that port.
     */
    public function rewriteVhosts(array $account, int $port): void
    {
        // Ensure the account nginx config tree (conf.d dir) exists before writing.
        $this->write($account);

        // Most-recently-touched row wins per label: duplicate website rows can
        // exist for one domain, but they share a single vhost file.
        $websites = app('db')->select(
            'SELECT * FROM `websites` WHERE `account_id` = ? AND `status` = ?
             ORDER BY `updated_at` DESC, `id` DESC',
            [(int) $account['id'], 'active']
        );

        $written = [];
        // Self-heal zone drift: if any site here is rate-limited, make sure
        // the shared zone file defines its zone before the front-door blocks
        // referencing it are tested. Best-effort — a real failure still
        // surfaces loudly from the vhost write's own `nginx -t`.
        foreach ($websites as $probe) {
            $connCap = 0;
            try {
                $connCap = (int) app(\Modules\Websites\Services\RateLimitService::class)
                    ->effectiveConnections($probe, $account)['cap'];
            } catch (\Throwable $e) {
                $connCap = 0;
            }
            if (!empty($probe['rate_limit_enabled']) || $connCap > 0) {
                try {
                    app(\Modules\Websites\Services\RateLimitService::class)->syncZones();
                } catch (\Throwable $e) {
                    app('logger')->warning('Rate-limit zone pre-sync failed: ' . $e->getMessage());
                }
                break;
            }
        }
        foreach ($websites as $website) {
            $name = $website['label'] ?? '';
            if ($name === '' || isset($written[$name])
                || $website['nginx_config'] === '' || $website['nginx_config'] === null
            ) {
                continue;
            }
            $written[$name] = true;

            // Per-account vhost: same document root/fastcgi as the front-door
            // config, but bound to the dedicated loopback port and with the
            // ModSecurity/WAF directives stripped (WAF runs at the front-door,
            // which is the only hop where the module is loaded).
            // WAF self-heal (see the generator): the front-door block only
            // references an existing rules file, so write a missing one first
            // instead of silently serving without protection.
            if ($this->isWafEnabled($website) && !is_file('/etc/modsecurity/' . $name . '.conf')) {
                try {
                    app(\Modules\Security\Services\WafService::class)->regenerate((int) ($website['id'] ?? 0));
                } catch (\Throwable $e) {
                    app('logger')->warning('WAF self-heal failed for ' . $name . ': ' . $e->getMessage());
                }
            }

            $perAccount = $this->stripWaf($this->rewriteListen((string) $website['nginx_config'], $port));
            // Bot-shield limit_req is likewise front-door-only: the worker
            // only ever sees 127.0.0.1, so enforcing there would throttle the
            // whole account as a single client.
            $perAccount = $this->stripRateLimit($perAccount);
            // TLS terminates at the front-door (root-owned certs the account
            // user cannot read); the worker only ever serves cleartext.
            $perAccount = $this->stripSsl($perAccount);
            $perAccount = $this->absolutizeIncludes($perAccount);
            $perAccount = $this->redirectLogs($perAccount, $account['username']);
            $perAccount = $this->forceForwardedParams($perAccount);
            $perAccount = $this->relativizeRedirects($perAccount);

            // Written by the agent as root:root 0644 (NOT FileService, which
            // chowns to the account). An account-writable conf.d let a
            // compromised account inject arbitrary nginx config and reload the
            // worker, bypassing every panel-side validation.
            app('provisioning')->execute('nginx.worker.vhost.write', [
                'account' => $account['username'],
                'name' => $name,
                'contents' => $perAccount,
            ]);

            // Front-door proxy: keep the shared nginx server block but forward to
            // the account port instead of serving PHP directly, carrying the WAF
            // directives when this site has ModSecurity enabled. TLS terminates
            // here too when the site has a certificate installed (the worker
            // copy is stripped of it above). Dedicated IPs ride along when
            // IP management is enabled (additive: wildcards always stay).
            $frontDoor = $this->frontDoorBlock(
                $name,
                $port,
                $this->isWafEnabled($website) && is_file('/etc/modsecurity/' . $name . '.conf'),
                !empty($website['maintenance_mode']) || !empty($website['suspended_bandwidth']),
                $this->rateLimitFor($website, $account),
                $this->frontDoorTls($name, $website),
                $this->frontDoorIps($website, $account),
                $this->connLimitFor($website, $account)
            );
            try {
                app('provisioning')->execute('nginx.logs.touch', ['name' => $name]);
            } catch (\Throwable $e) {
                app('logger')->warning('Nginx log pre-create failed for ' . $name . ': ' . $e->getMessage());
            }
            app('provisioning')->execute('nginx.vhost.write', [
                'name' => $name,
                'contents' => $frontDoor,
            ]);
        }
    }

    /**
     * Restore the account's original front-door vhosts (direct PHP serving) from
     * the stored websites.nginx_config. Called when isolation is disabled so the
     * shared nginx serves the account directly again.
     */
    public function restoreVhosts(array $account): void
    {
        $websites = app('db')->select(
            'SELECT * FROM `websites` WHERE `account_id` = ? AND `status` = ?
             ORDER BY `updated_at` DESC, `id` DESC',
            [(int) $account['id'], 'active']
        );

        $written = [];
        foreach ($websites as $website) {
            $name = $website['label'] ?? '';
            if ($name === '' || isset($written[$name])
                || $website['nginx_config'] === '' || $website['nginx_config'] === null
            ) {
                continue;
            }
            $written[$name] = true;

            app('provisioning')->execute('nginx.vhost.write', [
                'name' => $name,
                'contents' => (string) $website['nginx_config'],
            ]);
        }
    }

    /**
     * Rewrite a front-door server block to bind to a dedicated loopback port.
     * Replaces `listen 80`/`listen [::]:80` (and their `443 ssl` siblings
     * from TLS-enabled sites) with a single `listen 127.0.0.1:<port>`.
     * Dedicated-IP listens (`listen 1.2.3.4:80`, `listen [2001:db8::1]:443
     * ssl`) are dropped: the worker binds loopback only, and a public bind
     * as the account user would fail the worker start.
     */
    private function rewriteListen(string $config, int $port): string
    {
        // Dedicated-IP listens first (also covers the bare [::] form, same
        // outcome as the rule below it).
        $config = preg_replace('/^\s*listen\s+(?:\[[0-9a-fA-F:]+\]|[\d.]+):(?:80|443)(?:\s+ssl)?\s*;\s*$/m', '', $config) ?? $config;

        // Drop the IPv6 listen line entirely (the account worker only binds
        // loopback IPv4), and replace the IPv4 `listen 80`/`listen 443 ssl`
        // with a single `listen 127.0.0.1:<port>`.
        $config = preg_replace('/^\s*listen\s+\[::\]:(?:80|443)(?:\s+ssl)?\s*;?\s*$/m', '', $config) ?? $config;

        return preg_replace(
            '/^\s*listen\s+(?:80|443)(?:\s+ssl)?\s*;?\s*$/m',
            "    listen 127.0.0.1:{$port};",
            $config
        ) ?? $config;
    }

    /**
     * Strip ModSecurity directives (modsecurity / modsecurity_rules_file) from an
     * account vhost. The per-account nginx does not load the mod-security module;
     * WAF is enforced upstream at the shared front-door.
     */
    private function stripWaf(string $config): string
    {
        return preg_replace('/^\s*modsecurity(?:_rules_file)?\s+[^\n]*\n?/m', '', $config) ?? $config;
    }

    /**
     * Strip bot-shield limit_req/limit_conn directives from an account-worker
     * vhost copy. Enforcement lives at the front-door proxy (real client IP);
     * the worker only sees the proxy source address, so enforcing a per-site
     * connection cap there would throttle the whole account as one client.
     */
    private function stripRateLimit(string $config): string
    {
        $config = preg_replace('/^\s*limit_req(?:_status|_log_level)?\s+[^\n]*\n?/m', '', $config) ?? $config;

        return preg_replace('/^\s*limit_conn(?:_status|_log_level)?\s+[^\n]*\n?/m', '', $config) ?? $config;
    }

    /**
     * Strip TLS from a worker copy. TLS terminates at the front-door
     * (root-owned certs the account user cannot read); the worker only ever
     * serves cleartext loopback. The whole 443 server block is dropped: after
     * the listen rewrite both blocks would share one server_name and nginx
     * would route everything (including TLS-proxied traffic) into the first
     * block — a redirect loop the moment force_https is on. A stray-line
     * sweep follows as a safety net for malformed input.
     */
    private function stripSsl(string $config): string
    {
        $kept = '';
        $lines = explode("\n", $config);
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            if (preg_match('/^\s*server\s*\{\s*$/', $lines[$i])) {
                $depth = 0;
                $block = '';
                $j = $i;
                while ($j < $n) {
                    $block .= $lines[$j] . "\n";
                    $depth += substr_count($lines[$j], '{') - substr_count($lines[$j], '}');
                    $j++;
                    if ($depth <= 0) {
                        break;
                    }
                }
                if ($depth <= 0 && strpos($block, 'ssl_certificate') !== false) {
                    $i = $j; // TLS block: drop it entirely.
                    continue;
                }
                if ($depth > 0) {
                    // Unbalanced (custom snippet with stray braces?): fail
                    // open and keep the remainder verbatim.
                    $kept .= implode("\n", array_slice($lines, $i));
                    break;
                }
                $kept .= $block;
                $i = $j;
                continue;
            }
            $kept .= $lines[$i] . "\n";
            $i++;
        }
        $config = preg_replace('/^\s*ssl_certificate(?:_key)?\s+[^\n]*\n?/m', '', $kept) ?? $kept;
        $config = preg_replace('/^\s*ssl_protocols\s+[^\n]*\n?/m', '', $config) ?? $config;
        $config = preg_replace('/^\s*ssl_ciphers\s+[^\n]*\n?/m', '', $config) ?? $config;
        return preg_replace('/^\s*ssl_prefer_server_ciphers\s+[^\n]*\n?/m', '', $config) ?? $config;
    }

    /**
     * Effective rate limit for a front-door proxy block, or null when the
     * site is unprotected. Never throws: vhost pushes must survive a missing
     * package row or a pre-migration schema.
     *
     * @return array{zone:string, burst:int}|null
     */
    private function rateLimitFor(array $website, array $account): ?array
    {
        try {
            $eff = app(\Modules\Websites\Services\RateLimitService::class)->effective($website, $account);
        } catch (\Throwable $e) {
            return null;
        }
        if (empty($eff['enabled']) || empty($eff['zone'])) {
            return null;
        }
        return ['zone' => (string) $eff['zone'], 'burst' => (int) $eff['burst']];
    }

    /** Effective per-site concurrent-connection cap for the front-door block. */
    private function connLimitFor(array $website, array $account): ?array
    {
        try {
            $eff = app(\Modules\Websites\Services\RateLimitService::class)->effectiveConnections($website, $account);
        } catch (\Throwable $e) {
            return null;
        }
        if (($eff['cap'] ?? 0) <= 0 || empty($eff['zone'])) {
            return null;
        }

        return ['zone' => (string) $eff['zone'], 'cap' => (int) $eff['cap']];
    }

    /**
     * Rewrite relative `include` paths to absolute ones. The per-account nginx
     * resolves relative includes against its config dir (not the system prefix),
     * so `include fastcgi_params` must become `include /etc/nginx/fastcgi_params`.
     */
    private function absolutizeIncludes(string $config): string
    {
        return preg_replace(
            '/^\s*include\s+(?!\/)([A-Za-z0-9_.-]+)\s*;?\s*$/m',
            '    include /etc/nginx/$1;',
            $config
        ) ?? $config;
    }

    /**
     * Redirect access/error logs to the account's own logs dir. The per-account
     * nginx runs as the account user and cannot write to /var/log/nginx.
     */
    private function redirectLogs(string $config, string $username): string
    {
        $config = preg_replace(
            '/^\s*access_log\s+(?!\/home\/)\/var\/log\/nginx\/[^;]+;/m',
            "    access_log /home/{$username}/logs/access.log;",
            $config
        ) ?? $config;

        return preg_replace(
            '/^\s*error_log\s+(?!\/home\/)\/var\/log\/nginx\/[^;]+;/m',
            "    error_log /home/{$username}/logs/error.log;",
            $config
        ) ?? $config;
    }

    /**
     * nginx's own directory redirects (e.g. `/dir` -> `/dir/`) are absolute by
     * default and append the worker's listen port, leaking
     * http://host:<worker-port>/. Emit them RELATIVE so the client keeps the
     * front door's real scheme and host.
     */
    private function relativizeRedirects(string $config): string
    {
        return preg_replace_callback(
            '/^([ \t]*)listen\s+127\.0\.0\.1:\d+;\s*$/m',
            fn (array $m): string => $m[0] . "\n{$m[1]}absolute_redirect off;\n{$m[1]}port_in_redirect off;",
            $config
        ) ?? $config;
    }

    /**
     * The per-account worker runs cleartext HTTP on a loopback port behind the
     * TLS front door. PHP apps that build absolute URLs from SERVER_PORT or
     * $_SERVER['HTTPS'] (WordPress/DokuWiki canonical-slash redirects, Matomo)
     * would otherwise emit http://host:<worker-port>/... . Override those
     * fastcgi params from the front door's X-Forwarded-Proto via the
     * $cwh_https/$cwh_port/$cwh_scheme maps in the worker nginx.conf.
     */
    private function forceForwardedParams(string $config): string
    {
        return preg_replace_callback(
            '/^([ \t]*)include\s+\S*fastcgi_params;\s*$/m',
            function (array $m): string {
                $i = $m[1];
                return $m[0]
                    . "\n{$i}fastcgi_param HTTPS \$cwh_https;"
                    . "\n{$i}fastcgi_param SERVER_PORT \$cwh_port;"
                    . "\n{$i}fastcgi_param REQUEST_SCHEME \$cwh_scheme;"
                    . "\n{$i}fastcgi_param HTTP_X_FORWARDED_PROTO \$http_x_forwarded_proto;";
            },
            $config
        ) ?? $config;
    }

    private function isWafEnabled(array $website): bool
    {
        $websiteId = (int) ($website['id'] ?? 0);
        if ($websiteId <= 0) {
            return false;
        }

        $row = app('db')->selectOne(
            'SELECT `waf_enabled` FROM `security_settings` WHERE `website_id` = ?',
            [$websiteId]
        );

        if ($row === null) {
            // No settings row yet (new/legacy site): follow the global default.
            return app(\Modules\Security\Services\WafService::class)->defaultEnabled();
        }

        return (bool) $row['waf_enabled'];
    }

    /**
     * TLS descriptor for a front-door proxy block, or null when the site has
     * no certificate installed. Probed by file existence (same paths the
     * generator keys its 443 sibling on), never by blindly trusting the row.
     *
     * @return array{cert:string,key:string,hsts:int}|null
     */
    private function frontDoorTls(string $name, array $website): ?array
    {
        $domain = strtolower(trim($name));
        $cert = '/etc/cwhpanel/ssl/' . $domain . '/fullchain.pem';
        $key = '/etc/cwhpanel/ssl/' . $domain . '/key.pem';
        if (!is_file($cert) || !is_file($key)) {
            return null;
        }
        return ['cert' => $cert, 'key' => $key, 'hsts' => max(0, (int) ($website['hsts_max_age'] ?? 0))];
    }

    /**
     * Dedicated IPs for a front-door proxy block, or null when IP
     * management is off / nothing assigned. Mirrors the generator's
     * injectDedicatedIps so shared and isolated paths agree.
     *
     * @return array{v6?:string, v4?:string}|null
     */
    private function frontDoorIps(array $website, array $account): ?array
    {
        try {
            if (!app(\Modules\Network\Services\IpAllocatorService::class)->enabled()) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        $out = [];
        $v6 = app(\Modules\Network\Services\IpAllocatorService::class)->effectiveIpv6($account);
        if ($v6 !== '' && filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $out['v6'] = $v6;
        }
        $v4 = trim((string) ($website['ipv4_address'] ?? ''));
        if ($v4 !== '' && filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $out['v4'] = $v4;
        }
        return $out === [] ? null : $out;
    }

    /**
     * Front-door server block that terminates TLS/HTTP upstream and proxies all
     * traffic for the domain to the account's dedicated nginx worker. Carries the
     * ModSecurity WAF directives when enabled for the site, the bot-shield
     * limit_req when the site is rate-limited, or answers 503 for
     * everything when the site is in maintenance mode. When $tls is set, a 443
     * sibling terminates TLS here (certs are root-owned; the worker copy is
     * stripped of them) and applies HSTS while configured. $ips carries
     * additive dedicated listens (['v6' => addr, 'v4' => addr]) appended to
     * both blocks; wildcards stay, so unbound addresses never break nginx.
     */
    private function frontDoorBlock(string $name, int $port, bool $wafEnabled, bool $maintenance = false, ?array $rateLimit = null, ?array $tls = null, ?array $ips = null, ?array $connLimit = null): string
    {
        $name = strtolower(trim($name));

        $dedicated80 = '';
        $dedicated443 = '';
        if (is_array($ips)) {
            if (!empty($ips['v6']) && preg_match('/^[0-9a-fA-F:]+$/', (string) $ips['v6']) && filter_var($ips['v6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $dedicated80 .= "    listen [{$ips['v6']}]:80;\n";
                $dedicated443 .= "    listen [{$ips['v6']}]:443 ssl;\n";
            }
            if (!empty($ips['v4']) && filter_var($ips['v4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $dedicated80 .= "    listen {$ips['v4']}:80;\n";
                $dedicated443 .= "    listen {$ips['v4']}:443 ssl;\n";
            }
        }

        // Only reference a rules file that exists: a stale directive makes
        // `nginx -t` fail for the WHOLE host, so a vhost can never be written
        // (or reloaded) until someone fixes it by hand. The generator has the
        // same guard; this is the front-door path.
        $waf = ($wafEnabled && is_file('/etc/modsecurity/' . $name . '.conf'))
            ? "    # Web Application Firewall (ModSecurity)\n    modsecurity on;\n    modsecurity_rules_file /etc/modsecurity/{$name}.conf;\n"
            : '';

        $rl = ($rateLimit !== null && !$maintenance)
            ? "    # Bot shield (rate limiting)\n    limit_req zone={$rateLimit['zone']} burst={$rateLimit['burst']} nodelay;\n    limit_req_status 429;\n    limit_req_log_level warn;\n"
            : '';

        $conn = ($connLimit !== null && $connLimit['cap'] > 0 && !$maintenance)
            ? "    # Bot shield (concurrent connections)\n    limit_conn {$connLimit['zone']} {$connLimit['cap']};\n    limit_conn_status 429;\n"
            : '';

        $location = $maintenance
            ? "    location / {\n        add_header Retry-After 3600 always;\n        return 503;\n    }\n"
            : <<<NGINX
                location / {
                    proxy_pass http://127.0.0.1:{$port};
                    proxy_set_header Host \$host;
                    proxy_set_header X-Real-IP \$remote_addr;
                    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
                    proxy_set_header X-Forwarded-Proto \$scheme;
                }

            NGINX;

        $plain = <<<NGINX
server {
    listen 80;
    listen [::]:80;
{$dedicated80}    server_name {$name} www.{$name};

    access_log /var/log/nginx/{$name}.access.log;
    error_log  /var/log/nginx/{$name}.error.log;

{$waf}{$rl}{$conn}{$location}}

NGINX;

        if ($tls === null) {
            return $plain;
        }

        $hsts = $tls['hsts'] > 0
            ? "    add_header Strict-Transport-Security \"max-age={$tls['hsts']}\" always;\n"
            : '';

        return $plain . <<<NGINX

server {
    listen 443 ssl;
    listen [::]:443 ssl;
{$dedicated443}    server_name {$name} www.{$name};

    ssl_certificate {$tls['cert']};
    ssl_certificate_key {$tls['key']};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_prefer_server_ciphers on;
{$hsts}
    access_log /var/log/nginx/{$name}.access.log;
    error_log  /var/log/nginx/{$name}.error.log;

{$waf}{$rl}{$conn}{$location}}
NGINX;
    }

    private function record(int $accountId, int $port, string $status): void
    {
        $existing = app('db')->selectOne(
            'SELECT `id` FROM `nginx_workers` WHERE `account_id` = ?',
            [$accountId]
        );

        if ($existing !== null) {
            app('db')->update('nginx_workers', [
                'port' => $port,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`account_id` = ?', [$accountId]);
            return;
        }

        app('db')->insert('nginx_workers', [
            'account_id' => $accountId,
            'port' => $port,
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}