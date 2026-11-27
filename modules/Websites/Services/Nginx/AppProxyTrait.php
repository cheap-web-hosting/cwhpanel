<?php

declare(strict_types=1);

namespace Modules\Websites\Services\Nginx;

/**
 * @internal Extracted from NginxConfigGenerator (concern group).
 */
trait AppProxyTrait
{


    /**
     * Reverse-proxy locations for runtime-catalog apps (Node.js/Python/
     * binary) mounted on this website. Subdirectory mounts become legal
     * `location ^~ /sub/` blocks; a ROOT mount (base_path '/') cannot — nginx
     * forbids two locations named "/" — so it is surfaced in $rootProxy for
     * generate() to swap the site's `location /` front controller with.
     */
    private function injectAppProxy(string $config, array $website, string &$rootProxy = ''): string
    {
        $websiteId = (int) ($website['id'] ?? 0);
        if ($websiteId <= 0) {
            return $config;
        }
        try {
            $apps = app('db')->select(
                'SELECT `name`, `port`, `base_path` FROM `applications`
                 WHERE `website_id` = ? AND `port` IS NOT NULL ORDER BY `base_path`',
                [$websiteId]
            );
        } catch (\Throwable $e) {
            return $config;
        }
        // L-14: when app network namespaces are enabled, the app listens in
        // its account's namespace and the host proxies to that address.
        try {
            $proxyHost = app(\Modules\Network\Services\AppNetnsService::class)
                ->proxyHost((int) ($website['account_id'] ?? 0));
        } catch (\Throwable $e) {
            $proxyHost = '127.0.0.1';
        }

        $rootProxy = '';
        $blocks = '';
        foreach ($apps as $app) {
            $port = (int) ($app['port'] ?? 0);
            if ($port < 1024 || $port > 65535) {
                continue;
            }
            try {
                $prefix = $this->escapeProxyPrefix((string) ($app['base_path'] ?? '/'));
            } catch (\Throwable $e) {
                continue;
            }
            $name = preg_replace('/[^a-z0-9_-]/i', '', (string) ($app['name'] ?? 'app'));
            if ($prefix === '/') {
                // Root mount: swapped into `location /` by generate() — never
                // injected as a second "/" location (fatal duplicate).
                $rootProxy = $this->appProxyLocation($name, $proxyHost, $port, '/');
                continue;
            }
            $blocks .= $this->appProxyLocation($name, $proxyHost, $port, $prefix);
        }
        if ($blocks === '') {
            return $config;
        }
        return $this->injectBeforeServerDeny($config, $blocks);
    }


    /**
     * One proxy location block for an app process. $prefix is the escaped
     * mount ('/' for a root install, '/sub/' for a subdirectory); a root mount
     * omits the `^~` modifier because it REPLACES the template's `location /`.
     */
    private function appProxyLocation(string $name, string $host, int $port, string $prefix): string
    {
        $location = $prefix === '/' ? 'location / {' : "location ^~ {$prefix} {";
        return "\n    # App: {$name} (proxied process on {$host}:{$port})\n"
            . "    {$location}\n"
            . "        proxy_pass http://{$host}:{$port}/;\n"
            . "        proxy_http_version 1.1;\n"
            . "        proxy_set_header Host \$host;\n"
            . "        proxy_set_header X-Real-IP \$remote_addr;\n"
            . "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n"
            . "        proxy_set_header X-Forwarded-Proto \$scheme;\n"
            . "        proxy_set_header Upgrade \$http_upgrade;\n"
            . "        proxy_set_header Connection \"upgrade\";\n"
            . "    }\n";
    }

    /**
     * URL prefix for an app mount: '/' for domain roots, '/sub/' otherwise.
     * Same strict charset as protected-dir paths (no regex chars, since the
     * location itself is a prefix match, never a regex).
     */
    private function escapeProxyPrefix(string $path): string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '/';
        }
        if (strlen($path) > 64 || str_contains($path, '..') || str_contains($path, "\n")
            || str_contains($path, ';') || !preg_match('#^[A-Za-z0-9._/-]+$#', $path)
        ) {
            throw new \RuntimeException("Invalid app base path [{$path}].");
        }
        return rtrim($path, '/') . '/';
    }
}
