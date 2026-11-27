<?php

declare(strict_types=1);

namespace Modules\Websites\Services\Nginx;

use Modules\Websites\Services\PhpDirService;

/**
 * @internal Extracted from NginxConfigGenerator (concern group).
 */
trait PhpDirsTrait
{


    /**
     * Per-directory PHP overrides: one `location ^~ /dir/` block per
     * effective row, routing .php to the pinned version socket with the
     * row's locked options as PHP_ADMIN_VALUE. Fully-inherited rows never
     * reach here (effectiveFor drops them). The nested dotfiles deny keeps
     * `^~` from shadowing the server-level deny (a bare prefix match
     * stops regex evaluation, so without it /dir/.git/ would serve).
     * Longest prefix wins between nested dirs; overlapping Directory
     * Privacy blocks resolve the same way (version follows the winner).
     */
    private function injectPhpDirs(string $config, array $website, array $account): string
    {
        $websiteId = (int) ($website['id'] ?? 0);
        if ($websiteId <= 0) {
            return $config;
        }
        try {
            $rows = app(PhpDirService::class)->effectiveFor($website);
        } catch (\Throwable $e) {
            return $config;
        }
        if ($rows === []) {
            return $config;
        }
        $username = $this->escapeIdentifier($account['username'] ?? '');
        if ($username === '') {
            return $config;
        }
        // Dirs serving a framework webroot (Laravel public/, …) are routed
        // by injectSubdirApps' alias block — which already carries the
        // pinned socket. A per-dir block here would be a second identical-
        // prefix location and nginx would refuse the config. (Corner: row
        // settings are then inert; the app owns its runtime.)
        // ?page=<slug> routers on pinned plain dirs (same exactly-one-block
        // rule: a pinned dir without a webroot is served HERE, so its page
        // rewrite must ride along — otherwise pinning breaks its routing).
        $webrootDirs = [];
        $pageDirs = [];
        try {
            $docroot = rtrim((string) ($website['document_root'] ?? ''), '/');
            $accountId = (int) ($website['account_id'] ?? 0);
            if ($docroot !== '' && $accountId > 0) {
                foreach (app('db')->select(
                    "SELECT `directory`, `application_slug` FROM `ai_installations`
                      WHERE `account_id` = ? AND `status` != 'uninstalled'
                        AND `directory` != '' AND `path` LIKE ?",
                    [$accountId, $docroot . '/%']
                ) as $irow) {
                    $dirKey = trim((string) ($irow['directory'] ?? ''), '/');
                    if ($this->appWebroot((string) ($irow['application_slug'] ?? '')) !== '') {
                        $webrootDirs[$dirKey] = true;
                    }
                    $slugKey = (string) ($irow['application_slug'] ?? '');
                    if ($dirKey !== '' && $this->appPageParam($slugKey) !== '') {
                        $pageDirs[$dirKey] = $slugKey;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        $blocks = '';
        foreach ($rows as $row) {
            $prefix = '/' . trim((string) $row['dir'], '/') . '/';
            if ($prefix === '//' || strlen($prefix) > 257 || str_contains($prefix, '..')
                || preg_match('#^[A-Za-z0-9._/-]+$#', $prefix) !== 1
                || isset($webrootDirs[trim((string) $row['dir'], '/')])
            ) {
                continue;
            }
            $socket = '/run/php-fpm/cwh_' . $username
                . \Modules\Core\Services\PhpVersionsService::poolSuffix((string) $row['version_id']) . '.sock';
            $dirKey = trim((string) $row['dir'], '/');
            // Same @pg_<dir> tag pageFrontNamed() emits below.
            $ppFallback = isset($pageDirs[$dirKey])
                ? '@pg_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($dirKey))
                : "{$prefix}index.php?\$query_string";
            $adminLines = '';
            foreach ((array) ($row['settings'] ?? []) as $key => $value) {
                if (!isset(\Modules\Core\Services\PhpVersionsService::OPTIONS[$key])) {
                    continue;
                }
                $value = (string) $value;
                if (\Modules\Core\Services\PhpVersionsService::OPTIONS[$key][1] === 'onoff') {
                    if ($value !== '0' && $value !== '1') {
                        continue;
                    }
                } elseif (preg_replace('/[^A-Za-z0-9_.-]/', '', $value) !== $value || $value === '') {
                    continue;
                }
                $adminLines .= $key . '=' . $value . "\n";
            }
            $adminLines = rtrim($adminLines);
            $param = $adminLines !== ''
                ? "            fastcgi_param PHP_ADMIN_VALUE \"" . str_replace("\n", "\\n", $adminLines) . "\";\n"
                : '';
            $blocks .= "\n    # Per-directory PHP: {$prefix} (PHP " . $row['version_id'] . ")\n"
                . "    location ^~ {$prefix} {\n"
                . "        try_files \$uri \$uri/ {$ppFallback};\n"
                . "        location ~ [^/]\\.php(/|\$) {\n"
                . "            fastcgi_split_path_info ^(.+?\\.php)(/.*)\$;\n"
                . "            include fastcgi_params;\n"
                . "            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n"
                . "            fastcgi_param PATH_INFO \$fastcgi_path_info;\n"
                . "            fastcgi_pass unix:{$socket};\n"
                . $param
                . "        }\n"
                . "        location ~ /\\. {\n"
                . "            deny all;\n"
                . "        }\n"
                . "    }\n";
            if (isset($pageDirs[$dirKey])) {
                $blocks .= $this->pageFrontNamed(
                    $dirKey,
                    $this->appPageParam($pageDirs[$dirKey]),
                    $this->appFrontController($pageDirs[$dirKey])
                );
            }
        }
        if ($blocks === '') {
            return $config;
        }
        return $this->injectBeforeServerDeny($config, $blocks);
    }


    /** FPM socket suffix for the website's PHP version ('' for default 8.4). */
    private function phpSuffix(array $website): string
    {
        $version = (string) ($website['php_version'] ?? '');
        return \Modules\Core\Services\PhpVersionsService::poolSuffix($version);
    }
}
