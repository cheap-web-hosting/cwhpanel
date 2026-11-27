<?php

declare(strict_types=1);

namespace Modules\Websites\Services\Nginx;

use Modules\Websites\Services\RateLimitService;

/**
 * @internal Extracted from NginxConfigGenerator (concern group).
 */
trait SecurityBlocksTrait
{
    /**
     * Directives that may never appear in a customer snippet: they would
     * escape the server block, hijack ports/names, or read outside files.
     * Everything else is allowed — nginx -t is the final gate, and on
     * isolated accounts a bad snippet can only break that account's worker.
     */
    private const DENIED_DIRECTIVES = [
        // Server/process-level (escape the block, hijack ports/names).
        'listen', 'server_name', 'user', 'pid', 'daemon', 'master_process',
        'worker_processes', 'worker_rlimit_nofile', 'error_log', 'access_log',
        'include', 'load_module', 'env', 'lock_file', 'timer_resolution',
        'auth_basic_user_file', 'ssl_certificate', 'ssl_certificate_key',
        'limit_req_zone', 'limit_conn_zone',
        // File disclosure (arbitrary path read/serve).
        'alias', 'root', 'autoindex', 'dav_methods', 'dav_access',
        // SSRF / cross-account upstreams (proxy or execute another socket).
        'proxy_pass', 'fastcgi_pass', 'fastcgi_param', 'fastcgi_index',
        'fastcgi_split_path_info', 'fastcgi_cache', 'fastcgi_store',
        'uwsgi_pass', 'scgi_pass', 'grpc_pass', 'proxy_cache', 'proxy_store',
        // Embedded code / dynamic modules.
        'perl', 'lua', 'mirror',
    ];

    private function injectCustom(string $config, array $website): string
    {
        $snippet = trim((string) ($website['custom_nginx'] ?? ''));
        if ($snippet === '') {
            return $config;
        }
        if (strlen($snippet) > 8192) {
            throw new \RuntimeException('Custom directives exceed 8 KB.');
        }
        // Work on a copy with comments and quoted strings removed so a denied
        // token inside a header value/regex is not a false positive, and so
        // braces inside strings do not confuse the balance check. The denylist
        // is then applied to EVERY token on a line (not just the first), which
        // closes the `location /x { alias /etc; }` single-line bypass.
        $clean = $this->stripCommentsAndQuoted($snippet);
        foreach (explode("\n", $clean) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match_all('/[a-z_]+/i', $line, $m)) {
                foreach ($m[0] as $tok) {
                    if (in_array(strtolower($tok), self::DENIED_DIRECTIVES, true)) {
                        throw new \RuntimeException("Directive not allowed in custom snippet: {$tok}.");
                    }
                }
            }
            if (str_contains($line, '{') && !preg_match('/^\s*(location|if|limit_except)\b/i', $line)) {
                throw new \RuntimeException('Only location/if/limit_except blocks allowed.');
            }
        }
        // Brace balance: the snippet is injected inside the server block. A
        // stray top-level `}` would close the server and open a sibling
        // `server { listen ...; }`, so require balanced braces that never dip
        // below the starting depth.
        $depth = 0;
        for ($i = 0, $n = strlen($clean); $i < $n; $i++) {
            if ($clean[$i] === '{') {
                $depth++;
            } elseif ($clean[$i] === '}') {
                $depth--;
                if ($depth < 0) {
                    throw new \RuntimeException('Unbalanced braces in custom snippet.');
                }
            }
        }
        if ($depth !== 0) {
            throw new \RuntimeException('Unbalanced braces in custom snippet.');
        }

        $indented = implode("\n", array_map(
            fn ($l) => $l === '' ? '' : '    ' . $l,
            explode("\n", $snippet)
        ));

        $trimmed = rtrim($config);
        if (!str_ends_with($trimmed, '}')) {
            throw new \RuntimeException('Cannot inject custom directives.');
        }
        return substr($trimmed, 0, -1)
            . "\n\n    # Custom directives (customer-managed)\n"
            . $indented . "\n}\n";
    }


    /**
     * Blank out `#` comments and single/double-quoted strings (backslash-aware)
     * so directive tokens and braces inside values/regexes are neither matched
     * by the denylist nor counted for brace balance.
     */
    private function stripCommentsAndQuoted(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '#') {
                while ($i < $len && $s[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";
                continue;
            }
            if ($c === '"' || $c === "'") {
                $q = $c;
                $i++;
                while ($i < $len) {
                    if ($s[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($s[$i] === $q) {
                        break;
                    }
                    $i++;
                }
                $out .= ' ';
                continue;
            }
            $out .= $c;
        }
        return $out;
    }


    /**
     * Bot-shield injection. Only fires when the website row carries an id +
     * an enabled flag (creation-time generates run pre-insert and stay
     * unprotected, which matches the disabled default).
     */
    private function injectRateLimit(string $config, array $website, array $account, string $documentRoot): string
    {
        $websiteId = (int) ($website['id'] ?? 0);
        if ($websiteId <= 0 || empty($website['rate_limit_enabled'])) {
            return $config;
        }
        $eff = app(RateLimitService::class)->effective($website, $account);
        if (!$eff['enabled']) {
            return $config;
        }
        $zone = RateLimitService::zoneName($websiteId);
        $burst = $eff['burst'];
        $block = "\n    # Bot shield (rate limiting)\n    limit_req zone={$zone} burst={$burst} nodelay;\n    limit_req_status 429;\n    limit_req_log_level warn;\n";
        return str_replace(
            "    root {$documentRoot};\n",
            "    root {$documentRoot};\n{$block}",
            $config
        );
    }

    /**
     * Concurrent-connection cap (package limit / per-site override). Keyed by
     * $server_name, so the ceiling applies to the whole site rather than per
     * client. Front-door only — the worker copy is stripped (same reason as
     * limit_req). No-op when the effective cap is 0.
     */
    private function injectConnLimit(string $config, array $website, array $account, string $documentRoot): string
    {
        $websiteId = (int) ($website['id'] ?? 0);
        if ($websiteId <= 0) {
            return $config;
        }
        $eff = app(RateLimitService::class)->effectiveConnections($website, $account);
        if ($eff['cap'] <= 0 || $eff['zone'] === null) {
            return $config;
        }
        $block = "\n    # Bot shield (concurrent connections)\n    limit_conn {$eff['zone']} {$eff['cap']};\n    limit_conn_status 429;\n";

        return str_replace(
            "    root {$documentRoot};\n",
            "    root {$documentRoot};\n{$block}",
            $config
        );
    }


    private function injectPrivacy(string $config, array $website, array $account): string
    {
        $websiteId = (int) ($website['id'] ?? 0);
        if ($websiteId <= 0) {
            return $config;
        }

        $dirs = app('db')->select(
            'SELECT * FROM `website_protected_dirs` WHERE `website_id` = ? AND `enabled` = 1 ORDER BY `path`',
            [$websiteId]
        );

        $blocks = '';
        foreach ($dirs as $dir) {
            $users = app('db')->select(
                'SELECT COUNT(*) AS n FROM `website_dir_users` WHERE `protected_dir_id` = ?',
                [(int) $dir['id']]
            );
            if ((int) ($users[0]['n'] ?? 0) <= 0) {
                continue;
            }
            try {
                $location = $this->escapeLocationPath((string) ($dir['path'] ?? ''));
                $title = $this->escapeAuthTitle((string) ($dir['title'] ?? 'Restricted'));
                $htpasswd = \Modules\Websites\Services\PrivacyService::htpasswdPath(
                    $account['username'], $websiteId, (int) $dir['id']
                );
            } catch (\Throwable $e) {
                continue;
            }
            $blocks .= "\n    # Directory Privacy: {$location}\n    location ^~ {$location} {\n        auth_basic \"{$title}\";\n        auth_basic_user_file {$htpasswd};\n    }\n";
        }

        if ($blocks === '') {
            return $config;
        }

        return $this->injectBeforeServerDeny($config, $blocks);
    }


    private function escapeLocationPath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || strlen($path) > 128
            || str_contains($path, '..') || str_contains($path, "\n") || str_contains($path, ';')
            || !preg_match('#^[A-Za-z0-9._/-]+$#', $path)
        ) {
            throw new \RuntimeException("Invalid protected path [{$path}].");
        }
        return '/' . $path . '/';
    }
}
