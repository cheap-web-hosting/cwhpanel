<?php

declare(strict_types=1);

namespace Modules\Websites\Services;

/**
 * Generates an Nginx server block for a website.
 *
 * The generated config is templated from allowlisted settings only — customers
 * can never inject arbitrary global Nginx directives. Output is validated
 * before being persisted (the write/reload pipeline lives in the Web Server
 * module, Phase 4+).
 */
class NginxConfigGenerator
{
    use \Modules\Websites\Services\Nginx\AppMountsTrait;
    use \Modules\Websites\Services\Nginx\AppProxyTrait;
    use \Modules\Websites\Services\Nginx\SecurityBlocksTrait;
    use \Modules\Websites\Services\Nginx\PhpDirsTrait;
    use \Modules\Websites\Services\Nginx\IpsTrait;
    use \Modules\Websites\Services\Nginx\EscapingTrait;

    private const TEMPLATE = <<<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name %SERVER_NAME%;

    root %DOCUMENT_ROOT%;
    index index.php index.html index.htm;

    access_log /var/log/nginx/%USERNAME%-%SERVER_NAME%.access.log;
    error_log  /var/log/nginx/%USERNAME%-%SERVER_NAME%.error.log;

    # ACME HTTP-01 must be servable on every site, always: force_https,
    # maintenance and HSTS logic below never touch this prefix (^~ beats
    # both the redirect location and the dotfiles regex). Without it the
    # `location ~ /\.` deny below 403s challenges and issuance/renewal dies.
    location ^~ /.well-known/acme-challenge/ {
        default_type text/plain;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PATH_INFO-capable PHP handler: apps in subdirectories (Nextcloud,
    # ownCloud, Joomla: /sub/index.php/login) must execute the addressed
    # .php file instead of falling through to the root front controller.
    # Plain /index.php hits still work (empty PATH_INFO, same socket).
    location ~ [^/]\.php(/|$) {
        fastcgi_split_path_info ^(.+?\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_pass unix:/run/php-fpm/cwh_%USERNAME%%PHP_SUFFIX%.sock;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX;

    /** Location block for versioned static assets (expires header only). */
    private function staticAssetBlock(): string
    {
        return "    # Public static assets (versioned via ?v=mtime — safe to cache long).\n"
            . "    location ~* \.(?:css|js|woff2?|eot|ttf|otf|svg|png|jpe?g|gif|webp|ico)$ {\n"
            . "        expires 7d;\n"
            . "        add_header Cache-Control \"public\";\n"
            . "    }\n\n";
    }

    /**
     * Insert $inject immediately before each server-level dotfiles deny.
     * The anchor MUST be line-anchored (^...$ with /m): a plain str_replace
     * on "    location ~ /\. {" also matches *inside* the 8-space nested
     * denies of already-injected per-dir/subdir blocks, which nested and
     * duplicated whole location blocks and failed `nginx -t` (bitten live:
     * mt-cron emitted inside nc-cron plus a duplicate top-level copy).
     */
    private function injectBeforeServerDeny(string $config, string $inject): string
    {
        $out = preg_replace_callback(
            '/^    location ~ \/\\\\. \\{$/m',
            static fn(array $m): string => $inject . $m[0],
            $config
        );
        return is_string($out) ? $out : $config;
    }

    public function generate(array $website, array $domain, array $account): string
    {
        $serverName = $this->escapeServerName($domain['name']);
        $documentRoot = $this->escapePath($website['document_root'] ?? '/home/' . $account['username'] . '/public_html');
        $username = $this->escapeIdentifier($account['username']);

        // Root installs of framework-webroot apps (Laravel public/, …)
        // serve from the webroot, not the install root: point the server
        // block there. Only completed installs count (a failed/pending row
        // must never reroute the docroot); uninstall restores it by the
        // same rule once its status flips.
        $rootStatics = [];
        $rootRoutes = [];
        $rawRoot = '';
        $rootWebroot = '';
        $rootPageParam = '';
        $rootPageFront = 'index.php';
        $rootDenies = [];
        try {
            $accountId = (int) ($account['id'] ?? 0);
            $rawRoot = rtrim((string) ($website['document_root'] ?? ''), '/');
            if ($accountId > 0 && $rawRoot !== '') {
                $roots = app('db')->select(
                    "SELECT `application_slug` FROM `ai_installations`
                      WHERE `account_id` = ? AND `status` = 'completed' AND `path` = ?",
                    [$accountId, $rawRoot]
                );
                foreach ($roots as $rrow) {
                    $wr = $this->appWebroot((string) ($rrow['application_slug'] ?? ''));
                    if ($wr !== '' && preg_match('#^[A-Za-z0-9._-]+$#', $wr) === 1) {
                        $documentRoot = $this->escapePath($rawRoot . '/' . $wr);
                        $rootWebroot = $rawRoot . '/' . $wr;
                        $rootStatics = $this->appWebrootStatic((string) ($rrow['application_slug'] ?? ''));
                        $rootRoutes = $this->appWebrootRoutes((string) ($rrow['application_slug'] ?? ''));
                        break;
                    }
                }
                // ?page=<slug> routers (WonderCMS) and sensitive-file denies
                // at the domain root: first completed install wins (one app
                // per docroot, same rule as the webroot rerouting above).
                foreach ($roots as $rrow) {
                    $slug = (string) ($rrow['application_slug'] ?? '');
                    $pp = $this->appPageParam($slug);
                    $dn = $this->appDenyPaths($slug);
                    if ($pp !== '' || $dn !== []) {
                        $rootPageParam = $pp;
                        $rootPageFront = $this->appFrontController($slug);
                        $rootDenies = $dn;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $config = str_replace(
            ['%SERVER_NAME%', '%DOCUMENT_ROOT%', '%USERNAME%', '%PHP_SUFFIX%'],
            [$serverName, $documentRoot, $username, $this->phpSuffix($website)],
            self::TEMPLATE
        );

        // Inject ModSecurity WAF directives when enabled for this website.
        // The vhost and the per-domain rules file are written by different code
        // paths, so either order must converge. A site with WAF enabled whose
        // file is missing (first save, a cleanup that removed it, an order
        // swap) gets its file written here — the directive is only emitted
        // when the target exists (a dangling one breaks `nginx -t` host-wide).
        if ($this->isWafEnabled($website) && !is_file('/etc/modsecurity/' . $domain['name'] . '.conf')) {
            try {
                app(\Modules\Security\Services\WafService::class)->regenerate((int) ($website['id'] ?? 0));
            } catch (\Throwable $e) {
                app('logger')->warning('WAF self-heal failed for ' . $domain['name'] . ': ' . $e->getMessage());
            }
        }
        if ($this->isWafEnabled($website) && is_file('/etc/modsecurity/' . $domain['name'] . '.conf')) {
            $safeDomain = $this->escapeServerName($domain['name']);
            $wafBlock = "\n    # Web Application Firewall (ModSecurity)\n    modsecurity on;\n    modsecurity_rules_file /etc/modsecurity/{$safeDomain}.conf;\n";
            $config = str_replace(
                "    root {$documentRoot};\n",
                "    root {$documentRoot};\n{$wafBlock}",
                $config
            );
        }

        // Inject bot-shield rate limiting (limit_req) when enabled. The zone
        // itself lives in the shared front-door zone file (synced by
        // RateLimitService); isolated workers strip these directives back out
        // (they only ever see 127.0.0.1) and enforce at the front-door proxy.
        $config = $this->injectRateLimit($config, $website, $account, $documentRoot);
        $config = $this->injectConnLimit($config, $website, $account, $documentRoot);

        // Inject password-protected directory blocks (Directory Privacy).
        $config = $this->injectPrivacy($config, $website, $account);

        // Inject reverse-proxy locations for catalog apps attached to this
        // website (one-click Node.js/Python installs + future custom apps).
        // Root-mounted apps ($rootProxyBlock) cannot be injected here: nginx
        // forbids two locations named "/", so they replace the site's
        // `location /` front controller below (after maintenance, before the
        // TLS clone).
        $rootProxyBlock = '';
        $config = $this->injectAppProxy($config, $website, $rootProxyBlock);

        // Inject per-directory PHP overrides (version and/or locked
        // options) ahead of the dotfiles deny (same slot as app mounts).
        $config = $this->injectPhpDirs($config, $website, $account);

        // Front controllers for catalog apps in subdirectories.
        $config = $this->injectSubdirApps($config, $website, $account);

        // Static asset dirs of root-installed webroot apps (served from
        // the install root beside the webroot, e.g. EspoCRM's client/).
        // Extra front controllers ride along the same way.
        if ($rootStatics !== [] || $rootRoutes !== []) {
            $staticBlocks = '';
            foreach ($rootStatics as $static) {
                $staticBlocks .= $this->webrootStaticBlock('', $static, $rawRoot . '/' . $static);
            }
            foreach ($rootRoutes as $prefix => $frontFile) {
                $rootSocket = '/run/php-fpm/cwh_' . $username . $this->phpSuffix($website) . '.sock';
                $staticBlocks .= $this->webrootRouteBlock('', $prefix, $frontFile, $rootWebroot, $rootSocket);
            }
            if ($staticBlocks !== '') {
                $config = $this->injectBeforeServerDeny($config, $staticBlocks);
            }
        }

        // Inject customer-managed directives (Custom includes).
        $config = $this->injectCustom($config, $website);

        // Maintenance mode: everything answers 503 (status-correct plain
        // block; a custom maintenance page can ride on custom includes).
        // suspended_bandwidth (billing hold) and suspended_account (admin
        // suspend) ride the same 503 path; both are admin-only to clear,
        // unlike the customer maintenance toggle.
        if (!empty($website['maintenance_mode']) || !empty($website['suspended_bandwidth']) || !empty($website['suspended_account'])) {
            $config = str_replace(
                "    location / {\n        try_files \$uri \$uri/ /index.php?\$query_string;\n    }\n",
                "    location / {\n        add_header Retry-After 3600 always;\n        return 503;\n    }\n",
                $config
            );
        }

        // Root-mounted proxied app (base_path '/'): the site's front
        // controller `location /` becomes a proxy to the app process. nginx
        // forbids two locations named "/" — adding `location ^~ /` alongside
        // the template's `location /` is a FATAL "duplicate location" that
        // fails `nginx -t` (bitten live: Vite Starter root mount on
        // myownhost.ovh). Runs AFTER maintenance so a sick site's
        // `return 503` stays authoritative (no try_files left to swap -> no-op)
        // and BEFORE the TLS clone below so the 443 sibling serves the app too.
        if ($rootProxyBlock !== '') {
            $swapped = str_replace(
                "    location / {\n        try_files \$uri \$uri/ /index.php?\$query_string;\n    }\n",
                $rootProxyBlock,
                $config
            );
            $config = $swapped !== false ? $swapped : $config;
        }

        // Per-site HTTPS. When a certificate is installed for the domain, a
        // 443 sibling carrying the same locations is appended (front-door and
        // worker copies derive from this via the isolation rewrites, which
        // already understand `listen 443 ssl`). force_https swaps the
        // cleartext location for a redirect (ACME challenge path exempt so
        // issuance/renewal keeps working); sick sites (503 above) redirect
        // nowhere, and without a certificate there is nothing to redirect
        // to, so force stays dormant. HSTS rides the TLS block only, and
        // only when configured.
        $certFile = '/etc/cwhpanel/ssl/' . $domain['name'] . '/fullchain.pem';
        $keyFile = '/etc/cwhpanel/ssl/' . $domain['name'] . '/key.pem';
        $hasCert = is_file($certFile) && is_file($keyFile);
        $sick = !empty($website['maintenance_mode']) || !empty($website['suspended_bandwidth']) || !empty($website['suspended_account']);
        if ($hasCert) {
            $tls = str_replace(
                ["    listen 80;\n    listen [::]:80;\n", "    server_name {$serverName};\n"],
                ["    listen 443 ssl;\n    listen [::]:443 ssl;\n", "    server_name {$serverName};\n    ssl_certificate {$certFile};\n    ssl_certificate_key {$keyFile};\n    ssl_protocols TLSv1.2 TLSv1.3;\n    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;\n    ssl_prefer_server_ciphers on;\n"],
                $config
            );
            $hsts = max(0, (int) ($website['hsts_max_age'] ?? 0));
            if ($hsts > 0) {
                $tls = str_replace(
                    "    ssl_prefer_server_ciphers on;\n",
                    "    ssl_prefer_server_ciphers on;\n    add_header Strict-Transport-Security \"max-age={$hsts}\" always;\n",
                    $tls
                );
            }
            $config .= "\n" . $tls;
        }
        if (!empty($website['force_https']) && !$sick && $hasCert && $rootProxyBlock === '') {
            // First occurrence only: the appended 443 sibling (below) must
            // keep serving, or https loops back onto itself. The redirect is
            // conditional on X-Forwarded-Proto so the single-block worker
            // copy (isolated accounts) serves front-door-443 traffic while
            // still redirecting front-door-80 traffic: the front-door proxy
            // always sets X-Forwarded-Proto ($scheme), and cleartext direct
            // hits carry no such header ("" != "https" -> redirect). The 443
            // block itself never redirects (limit 1 leaves it alone). NOTE:
            // the replacement adds NO acme exemption — the template already
            // carries exactly one, and a second identical
            // `location ^~ /.well-known/` block is a fatal duplicate that
            // kills isolated workers on restart.
            $config = preg_replace(
                '/    location \/ \{\n        try_files \$uri \$uri\/ \/index\.php\?\$query_string;\n    \}\n/',
                "    location / {\n        if (\$http_x_forwarded_proto != \"https\") {\n            return 301 https://\$host\$request_uri;\n        }\n        try_files \$uri \$uri/ /index.php?\$query_string;\n    }\n",
                $config,
                1
            ) ?? $config;
        }

        // ?page=<slug> routers at the domain root (WonderCMS parses
        // $_GET['page'] — pretty URLs die without the rewrite). Runs AFTER
        // maintenance/force_https so their block replacements still match
        // the pristine template first: this swaps only the try_files LINE
        // (identical in the plain and force_https forms, and in the 443
        // sibling, which needs it too). Sick sites (503, no try_files line)
        // are correctly a no-op.
        if ($rootPageParam !== '') {
            $swapped = str_replace(
                "        try_files \$uri \$uri/ /index.php?\$query_string;\n",
                "        try_files \$uri \$uri/ @pagefront;\n",
                $config
            );
            if ($swapped !== $config) {
                $config = $swapped;
                $config = $this->injectBeforeServerDeny($config, $this->pageFrontNamed('', $rootPageParam, $rootPageFront));
            }
        }
        // Sensitive-file denies at the domain root (credential stores like
        // WonderCMS data/database.js must never serve — the dotfiles regex
        // does not cover them).
        if ($rootDenies !== []) {
            $config = $this->injectBeforeServerDeny($config, $this->denyBlocks('', $rootDenies));
        }

        // Dedicated IPs (additive: wildcards stay, so a missing/unbound
        // address can never take the site down — it just never matches).
        // v6 comes from the account, v4 override from the website row
        // (NULL = shared fallback, already covered by the wildcards).
        $config = $this->injectDedicatedIps($config, $website, $account, $hasCert);

        // Public static assets (versioned via ?v=mtime — safe to cache long).
        // Injected before the dotfiles deny so `location ~ /\.` keeps its
        // precedence for hidden files; regex wins over `location /` anyway.
        $config = $this->injectBeforeServerDeny($config, $this->staticAssetBlock());

        return $config;
    }
}
