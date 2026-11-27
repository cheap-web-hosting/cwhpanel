<?php

declare(strict_types=1);

namespace Modules\Websites\Services\Nginx;

/**
 * @internal Extracted from NginxConfigGenerator (concern group).
 */
trait AppMountsTrait
{


    /**
     * Reverse-proxy locations for applications attached to this website
     * (applications.port set + website_id match). Mounted at the app's
     * base_path ('/' for domain roots). Upgrade headers are always sent so
     * websocket apps (Uptime Kuma, Node-RED…) work without extra config;
     * they are harmless for plain HTTP apps.
     *
     * The mount prefix is STRIPPED (proxy_pass with a URI part): catalog
     * apps serve root-relative paths and most cannot do subpaths
     * (http-server, Caddy file-server, dufs…), so /mount/foo reaches the
     * app as /foo. Domain-root mounts are unaffected either way.
     */
    /**
     * Front controllers for catalog apps installed in SUBDIRECTORIES of this
     * site's docroot. The server-level `location /` falls back to
     * `/index.php` (the DOMAIN root — e.g. the main WordPress), so a subdir
     * app's pretty URLs (Grav /admin, WordPress /sub/2024/…) would hit the
     * wrong front controller. One `location ^~ /sub/` per install routes
     * unknown paths to that subdir's own index.php, with the same
     * PATH_INFO-capable PHP handler as the server block.
     */
    private function injectSubdirApps(string $config, array $website, array $account): string
    {
        $docroot = rtrim((string) ($website['document_root'] ?? ''), '/');
        $accountId = (int) ($website['account_id'] ?? 0);
        if ($docroot === '' || $accountId <= 0) {
            return $config;
        }
        try {
            $installs = app('db')->select(
                "SELECT `directory`, `application_slug` FROM `ai_installations`
                  WHERE `account_id` = ? AND `status` != 'uninstalled'
                    AND `directory` != '' AND `path` LIKE ?
                  ORDER BY `directory`",
                [$accountId, $docroot . '/%']
            );
            // Dirs with a per-directory PHP override get their version from
            // the pin: use the pinned socket here. A pinned dir WITHOUT a
            // framework webroot is skipped (its front-controller block comes
            // from injectPhpDirs — a second identical-prefix location would
            // collide). A pinned dir WITH a webroot still needs the alias
            // block below (the per-dir block would serve the wrong docroot),
            // so only the no-webroot case skips.
            $pinned = [];
            try {
                $websiteId = (int) ($website['id'] ?? 0);
                if ($websiteId > 0) {
                    foreach (app('db')->select(
                        'SELECT `dir`, `php_version` FROM `website_php_dirs` WHERE `website_id` = ?',
                        [$websiteId]
                    ) as $prow) {
                        $pinned[trim((string) ($prow['dir'] ?? ''), '/')] = (string) ($prow['php_version'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
            return $config;
        }
        $username = $this->escapeIdentifier((string) ($account['username'] ?? ''));
        $socket = '/run/php-fpm/cwh_' . $username . $this->phpSuffix($website) . '.sock';
        $blocks = '';
        $namedBlocks = [];
        $seen = [];
        foreach ($installs as $row) {
            $dir = trim((string) ($row['directory'] ?? ''), '/');
            if ($dir === '' || isset($seen[$dir]) || str_contains($dir, '..')
                || !preg_match('#^[A-Za-z0-9._-]+$#', $dir)
            ) {
                continue;
            }
            $seen[$dir] = true;
            // Apps with a framework webroot under the install (Laravel
            // public/, etc.) must serve from that subdir: alias + the
            // alias-aware $request_filename.
            $slug = (string) ($row['application_slug'] ?? '');
            $webroot = $this->appWebroot($slug);
            $useWebroot = $webroot !== '' && preg_match('#^[A-Za-z0-9._-]+$#', $webroot) === 1;
            if (isset($pinned[$dir]) && !$useWebroot) {
                continue;
            }
            // Pinned socket when the dir carries a version override.
            $dirSocket = $socket;
            if (isset($pinned[$dir]) && preg_match('/^\d{2}$/', (string) $pinned[$dir]) === 1) {
                $dirSocket = '/run/php-fpm/cwh_' . $username
                    . \Modules\Core\Services\PhpVersionsService::poolSuffix((string) $pinned[$dir]) . '.sock';
            }
            if ($useWebroot) {
                $root = $docroot . '/' . $dir . '/' . $webroot . '/';
                // Front controller override (legacy Symfony apps serve
                // app.php, not index.php). Strictly validated, defaults to
                // index.php.
                $front = $this->appFrontController((string) ($row['application_slug'] ?? ''));
                // Named-location fallback: with `alias`, a try_files internal
                // redirect to /sub/<front> resolves under the alias prefix
                // and 404s, so dispatch to a server-level named location that
                // rewrites to the front controller instead.
                $named = 'lychee_' . preg_replace('/[^a-z0-9_]/', '_', $dir);
                $namedBlocks[$named] = "\n    location @{$named} {\n"
                    . "        rewrite ^ /{$dir}/{$front} last;\n"
                    . "    }\n";
                $blocks .= "\n    # App subdirectory: /{$dir}/ (webroot {$webroot}/)\n"
                    . "    location ^~ /{$dir}/ {\n"
                    . "        alias {$root};\n"
                    . "        index {$front} index.html;\n"
                    . "        try_files \$uri \$uri/ @{$named};\n"
                    . "        location ~ [^/]\\.php(/|\$) {\n"
                    . "            fastcgi_split_path_info ^(.+?\\.php)(/.*)\$;\n"
                    . "            include fastcgi_params;\n"
                    . "            fastcgi_param SCRIPT_FILENAME \$request_filename;\n"
                    . "            fastcgi_param PATH_INFO \$fastcgi_path_info;\n"
                    . "            fastcgi_pass unix:{$dirSocket};\n"
                    . "        }\n"
                . "        location ~ /\\. {\n"
                . "            deny all;\n"
                . "        }\n"
                . "    }\n";
                // Sibling static dirs served from the install root (EspoCRM
                // keeps client/ assets beside public/, not inside it).
                foreach ($this->appWebrootStatic($slug) as $static) {
                    $blocks .= $this->webrootStaticBlock($dir, $static, $docroot . '/' . $dir . '/' . $static);
                }
                // Extra front controllers under the webroot (EspoCRM's
                // public/api/v1/index.php serves /api/*, portal/ its own).
                foreach ($this->appWebrootRoutes($slug) as $prefix => $frontFile) {
                    $blocks .= $this->webrootRouteBlock($dir, $prefix, $frontFile, $docroot . '/' . $dir . '/' . $webroot, $dirSocket);
                }
                continue;
            }
            // ?page=<slug> routers (WonderCMS parses $_GET['page'] — the
            // pretty URL must reach the front controller as a query arg, or
            // every subpage (and the login POST) falls back to the homepage).
            $pageParam = $this->appPageParam($slug);
            $fallback = $pageParam !== ''
                ? '@pg_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($dir))
                : "/{$dir}/index.php?\$query_string";
            $blocks .= "\n    # App subdirectory: /{$dir}/ (own front controller)\n"
                . "    location ^~ /{$dir}/ {\n"
                . "        try_files \$uri \$uri/ {$fallback};\n"
                . "        location ~ [^/]\\.php(/|\$) {\n"
                . "            fastcgi_split_path_info ^(.+?\\.php)(/.*)\$;\n"
                . "            include fastcgi_params;\n"
                . "            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n"
                . "            fastcgi_param PATH_INFO \$fastcgi_path_info;\n"
                . "            fastcgi_pass unix:{$dirSocket};\n"
                . "        }\n"
                . "        location ~ /\\. {\n"
                . "            deny all;\n"
                . "        }\n"
                . "    }\n";
            if ($pageParam !== '') {
                $blocks .= $this->pageFrontNamed($dir, $pageParam, $this->appFrontController($slug));
            }
            // Sensitive-file denies (credential stores, caches).
            $blocks .= $this->denyBlocks($dir, $this->appDenyPaths($slug));
        }
        if ($blocks === '') {
            return $config;
        }
        $inject = $blocks . implode('', $namedBlocks);
        return $this->injectBeforeServerDeny($config, $inject);
    }


    /**
     * Framework webroot for a catalog app (manifest `webroot`), or '' when
     * the app serves from its install root. Best-effort: unknown slugs and
     * pre-migration tables return ''.
     */
    private function appWebroot(string $slug): string
    {
        if ($slug === '') {
            return '';
        }
        try {
            $row = app('db')->selectOne('SELECT `manifest` FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return '';
        }
        if ($row === null) {
            return '';
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        $webroot = is_array($manifest) ? (string) ($manifest['webroot'] ?? '') : '';
        return trim($webroot, '/');
    }


    /**
     * Static asset dirs served from the install root beside a framework
     * webroot (manifest `webroot_static`, e.g. EspoCRM's client/). Max 5,
     * strictly validated: names land verbatim in location blocks.
     *
     * @return array<int,string>
     */
    private function appWebrootStatic(string $slug): array
    {
        if ($slug === '') {
            return [];
        }
        try {
            $row = app('db')->selectOne('SELECT `manifest` FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return [];
        }
        if ($row === null) {
            return [];
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        $list = is_array($manifest) ? ($manifest['webroot_static'] ?? []) : [];
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach (array_values($list) as $entry) {
            $entry = trim((string) $entry, '/');
            if ($entry !== '' && preg_match('#^[A-Za-z0-9._-]+$#', $entry) === 1) {
                $out[] = $entry;
            }
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    }


    /**
     * Static-only location for one install-root asset dir: serves files,
     * never executes PHP (these trees hold JS/CSS/fonts), keeps its own
     * dotfiles deny so `^~` shadowing stays safe. $urlPrefix is '' at
     * server level or '/subdir' for mounts.
     */
    private function webrootStaticBlock(string $urlPrefix, string $static, string $absDir): string
    {
        $trimmed = trim($urlPrefix, '/');
        $loc = ($trimmed === '' ? '' : $trimmed . '/') . $static . '/';
        // $absDir is built from the validated account docroot + validated
        // segment names (same trust as the alias blocks).
        return "\n    # App static dir: /{$loc}\n"
            . "    location ^~ /{$loc} {\n"
            . "        alias {$absDir}/;\n"
            . "        location ~ [^/]\\.php(/|\$) {\n"
            . "            return 404;\n"
            . "        }\n"
            . "        location ~ /\\. {\n"
            . "            deny all;\n"
            . "        }\n"
            . "    }\n";
    }


    /**
     * Extra front controllers under a framework webroot (manifest
     * `webroot_routes`: prefix => front-controller path, e.g. EspoCRM's
     * api => api/v1/index.php). Prefix is one URL segment, front a
     * webroot-relative .php path. Max 5, strictly validated.
     *
     * @return array<string,string> prefix => front path
     */
    private function appWebrootRoutes(string $slug): array
    {
        if ($slug === '') {
            return [];
        }
        try {
            $row = app('db')->selectOne('SELECT `manifest` FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return [];
        }
        if ($row === null) {
            return [];
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        $map = is_array($manifest) ? ($manifest['webroot_routes'] ?? []) : [];
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $prefix => $frontFile) {
            $prefix = trim((string) $prefix, '/');
            $frontFile = ltrim(trim((string) $frontFile), '/');
            if ($prefix === '' || str_contains($prefix, '/') || str_contains($prefix, '..')
                || preg_match('#^[A-Za-z0-9._-]+$#', $prefix) !== 1
                || $frontFile === '' || str_contains($frontFile, '..')
                || preg_match('#^[A-Za-z0-9._/-]+\.php$#', $frontFile) !== 1
            ) {
                continue;
            }
            $out[$prefix] = $frontFile;
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    }


    /**
     * Dedicated front-controller location under a webroot mount: longer
     * prefix wins over the app block, so /sub/api/* reaches api/v1's
     * controller while the app block keeps serving everything else.
     * REQUEST_URI survives the internal redirect, which is what Slim-style
     * routers match on. $urlPrefix is '' at server level or the subdir.
     */
    private function webrootRouteBlock(string $urlPrefix, string $prefix, string $frontFile, string $webrootAbs, string $socket): string
    {
        $base = trim($urlPrefix, '/');
        $loc = ($base === '' ? '' : '/' . $base) . '/' . $prefix . '/';
        $frontUri = ($base === '' ? '/' : '/' . $base . '/') . $frontFile;
        $frontDir = rtrim(dirname($frontUri), '/');
        $tag = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($base . '_' . $prefix, '_')));
        // $webrootAbs is built from the validated account docroot +
        // validated segments (same trust as the alias blocks). The alias
        // MUST include the prefix dir: the front controller lives at
        // <webroot>/<prefix>/… (EspoCRM's public/api/v1/index.php), so
        // aliasing the bare webroot would resolve v1/* under public/v1/*
        // and 404 every API call (bitten live). The front controller path
        // is hardcoded (not $request_filename): under alias, that variable
        // would swallow PATH_INFO. The rewrite is anchored on the FRONT's
        // dir (not the bare prefix): the route path must be relative to
        // the front file (PATH_INFO /App/user, not /v1/App/user — Slim
        // strips SCRIPT_NAME, so a doubled segment 404s inside the app,
        // also bitten live). Rewritten front-controller URIs contain .php/,
        // so the nested regex location serves them directly without
        // re-entering try_files (no rewrite loop). REQUEST_URI survives
        // regardless, which is what Slim-style routers match on.
        return "\n    # App route: {$loc} (front {$frontFile})\n"
            . "    location ^~ {$loc} {\n"
            . "        alias {$webrootAbs}/{$prefix}/;\n"
            . "        try_files \$uri @rte_{$tag};\n"
            . "        location ~ [^/]\\.php(/|\$) {\n"
            . "            fastcgi_split_path_info ^(.+?\\.php)(/.*)\$;\n"
            . "            include fastcgi_params;\n"
            . "            fastcgi_param SCRIPT_FILENAME {$webrootAbs}/{$frontFile};\n"
            . "            fastcgi_param PATH_INFO \$fastcgi_path_info;\n"
            . "            fastcgi_pass unix:{$socket};\n"
            . "        }\n"
            . "        location ~ /\\. {\n"
            . "            deny all;\n"
            . "        }\n"
            . "    }\n"
            . "    location @rte_{$tag} {\n"
            . "        rewrite ^{$frontDir}/(.*)\$ {$frontUri}/\$1 last;\n"
            . "        rewrite ^{$loc}\$ {$frontUri}/ last;\n"
            . "    }\n";
    }

    private function appFrontController(string $slug): string
    {
        $front = 'index.php';
        if ($slug === '') {
            return $front;
        }
        try {
            $row = app('db')->selectOne('SELECT `manifest` FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return $front;
        }
        if ($row === null) {
            return $front;
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        $raw = is_array($manifest) ? trim((string) ($manifest['front'] ?? '')) : '';
        if ($raw !== '' && preg_match('#^[A-Za-z0-9._-]+\.php$#', $raw) === 1) {
            return $raw;
        }
        return $front;
    }


    /**
     * Query-arg router for a catalog app (manifest `page_param`, e.g.
     * WonderCMS routing via $_GET['page']). Strict: one [A-Za-z0-9_]
     * segment, max 32 chars — it lands verbatim in a rewrite.
     */
    private function appPageParam(string $slug): string
    {
        if ($slug === '') {
            return '';
        }
        try {
            $row = app('db')->selectOne('SELECT `manifest` FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return '';
        }
        if ($row === null) {
            return '';
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        $raw = is_array($manifest) ? trim((string) ($manifest['page_param'] ?? '')) : '';
        if ($raw !== '' && strlen($raw) <= 32 && preg_match('/^[A-Za-z0-9_]+$/', $raw) === 1) {
            return $raw;
        }
        return '';
    }


    /**
     * Sensitive install-relative paths a catalog app needs denied
     * (manifest `deny_paths`, e.g. WonderCMS data/database.js holding
     * credential hashes — the dotfiles regex does not cover them).
     * Max 10, strictly validated: entries land verbatim in locations.
     *
     * @return array<int,string>
     */
    private function appDenyPaths(string $slug): array
    {
        if ($slug === '') {
            return [];
        }
        try {
            $row = app('db')->selectOne('SELECT `manifest` FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return [];
        }
        if ($row === null) {
            return [];
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        $list = is_array($manifest) ? ($manifest['deny_paths'] ?? []) : [];
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach (array_values($list) as $entry) {
            $entry = trim((string) $entry, '/');
            if ($entry === '' || strlen($entry) > 128 || str_contains($entry, '..')
                || preg_match('#^[A-Za-z0-9._/-]+$#', $entry) !== 1
            ) {
                continue;
            }
            $out[] = $entry;
            if (count($out) >= 10) {
                break;
            }
        }
        return array_values(array_unique($out));
    }


    /**
     * Named-location fallback routing pretty URLs to the front controller
     * as ?<param>=<path> (QSA preserved). $urlPrefix is '' at server level
     * or the install subdir. The front defaults to index.php (relative to
     * the served dir, so webroot-adjusted docroots work unchanged).
     */
    private function pageFrontNamed(string $urlPrefix, string $param, string $front = 'index.php'): string
    {
        $base = trim($urlPrefix, '/');
        $tag = $base === '' ? 'pagefront' : 'pg_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($base));
        $loc = $base === '' ? '/' : '/' . $base . '/';
        $frontUri = $base === '' ? '/index.php' : '/' . $base . '/' . $front;
        // Trailing-& QSA form: harmless when $args is empty (PHP parses
        // `?page=x&` fine) and correct when query args ride along.
        return "\n    # App page router: {$loc} (?{$param}=)\n"
            . "    location @{$tag} {\n"
            . "        rewrite ^{$loc}(.*)\$ {$frontUri}?{$param}=\$1&\$args last;\n"
            . "    }\n";
    }


    /**
     * Deny locations for an app's sensitive paths: exact-file matches plus
     * trailing-slash subtree matches. $urlPrefix is '' at server level or
     * the install subdir; paths resolve under the served dir.
     */
    private function denyBlocks(string $urlPrefix, array $paths): string
    {
        if ($paths === []) {
            return '';
        }
        $base = trim($urlPrefix, '/');
        $prefix = $base === '' ? '' : '/' . $base;
        $out = '';
        foreach ($paths as $entry) {
            if (str_ends_with($entry, '/')) {
                $entry = rtrim($entry, '/');
                $out .= "\n    # App deny dir: {$prefix}/{$entry}/\n"
                    . "    location ^~ {$prefix}/{$entry}/ {\n"
                    . "        deny all;\n"
                    . "    }\n";
            } else {
                $out .= "\n    # App deny file: {$prefix}/{$entry}\n"
                    . "    location = {$prefix}/{$entry} {\n"
                    . "        deny all;\n"
                    . "    }\n";
            }
        }
        return $out;
    }
}
