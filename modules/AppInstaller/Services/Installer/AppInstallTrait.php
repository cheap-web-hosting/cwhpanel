<?php

declare(strict_types=1);

namespace Modules\AppInstaller\Services\Installer;

/**
 * @internal Extracted from InstallerService (concern group).
 */
trait AppInstallTrait
{


    /**
     * One-click app install: source the code (pip/npm/archive), register a
     * supervised process row with its own port, and mount it on the chosen
     * website so it answers HTTPS immediately.
     */
    private function runAppInstall(int $installationId, array $account, array $application, array $manifest, array $opts): void
    {
        // App-type installs provision a supervised runtime process: they need
        // the applications feature too, not just the installer that queued them.
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications');
        $username = $account['username'];
        $home = $account['home_dir'] ?? "/home/{$username}";
        $slug = (string) $application['slug'];
        $type = (string) $manifest['type'];

        if (!empty($manifest['database']['required'])) {
            throw new \RuntimeException('App-type manifests must not require a panel-managed database (the app wizard owns setup).');
        }

        // Forge-style post-boot setup (setup.cli on app installs): manifests
        // that finish with an in-tree command (forge admin creation) need
        // usable wizard values. Refuse before anything is provisioned, with
        // the same rules as the customer form.
        $appSetup = is_array($opts['setup'] ?? null) ? $opts['setup'] : [];
        $aUser = trim((string) ($appSetup['admin_user'] ?? ''));
        $aPass = (string) ($appSetup['admin_password'] ?? '');
        $aEmail = trim((string) ($appSetup['admin_email'] ?? ''));
        // `setup.cli` finishes via an in-tree command; `setup.requires_creds`
        // finishes purely via a seeded config file that interpolates {pass}.
        if (isset($manifest['setup']['cli']) || !empty($manifest['setup']['requires_creds'])) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $aUser) !== 1
                || strlen($aPass) < 8 || strlen($aPass) > 256
                || str_contains($aPass, "\n") || str_contains($aPass, "\0")
                || strlen($aEmail) > 254 || filter_var($aEmail, FILTER_VALIDATE_EMAIL) === false
            ) {
                throw new \RuntimeException('This application needs an admin username (1-32 chars), password (min 8 chars) and valid email to finish setup unattended.');
            }
        }

        // Resolve the mount website (account-scoped) + normalize base path.
        $websiteId = (int) ($opts['website_id'] ?? 0);
        $website = $websiteId > 0 ? app('db')->selectOne(
            'SELECT w.*, d.`name` AS domain_name FROM `websites` w
             LEFT JOIN `domains` d ON d.`id` = w.`domain_id`
             WHERE w.`id` = ? AND w.`account_id` = ?',
            [$websiteId, (int) $account['id']]
        ) : null;
        if ($website === null) {
            throw new \RuntimeException('Mount website not found (create the website first).');
        }
        $basePath = '/' . trim((string) ($opts['base_path'] ?? ($opts['directory'] ?? '')), '/');
        if ($basePath !== '/' && (!preg_match('#^[A-Za-z0-9._/-]+$#', $basePath) || str_contains($basePath, '..') || strlen($basePath) > 64)) {
            throw new \RuntimeException('Invalid mount path.');
        }
        $basePath = $basePath === '/' ? '/' : rtrim($basePath, '/');

        // Unique process name per account (the runner + unit derive from it).
        $taken = app('db')->selectOne(
            'SELECT `id` FROM `applications` WHERE `account_id` = ? AND `name` = ?',
            [(int) $account['id'], $slug]
        );
        if ($taken !== null) {
            throw new \RuntimeException("An app named '{$slug}' already exists on this account.");
        }

        $apps = app(\Modules\Applications\Services\AppService::class);
        $workDir = $apps->createWorkDir($username, $slug);
        $this->log($installationId, 'info', "Provisioned work dir {$workDir}");
        $port = \Modules\Applications\Services\AppService::allocatePort();
        $appRow = ['name' => $slug, 'port' => $port];

        if ($type === 'python-pip') {
            $runtime = 'python';
            $version = (string) ($manifest['runtime']['version'] ?? '3.11');
            $version = \Modules\Applications\Services\AppService::normalizeVersion('python', $version);
            $pkg = (string) ($manifest['pip']['package'] ?? '');
            $script = (string) ($manifest['pip']['script'] ?? '');
            if ($pkg === '' || $script === '') {
                throw new \RuntimeException('pip manifest needs package + script.');
            }
            $args = (array) ($manifest['pip']['args'] ?? []);
            $this->log($installationId, 'info', "Installing pip package {$pkg}");
            $apps->installAppPackage($account, ['name' => $slug, 'runtime_version' => $version] + $appRow, $pkg);
            $entry = $apps->writeLauncher($account, ['name' => $slug, 'port' => $port] + $appRow, 'python', $script, $args);
        } elseif ($type === 'node-npm' || $type === 'node-archive') {
            $runtime = 'node';
            $version = (string) ($manifest['runtime']['version'] ?? '22');
            $version = \Modules\Applications\Services\AppService::normalizeVersion('node', $version);
            if ($type === 'node-archive') {
                $this->installArchive($installationId, $account, $application, $manifest, $workDir, $home);
                $this->log($installationId, 'info', 'Installing npm dependencies');
                $apps->npmInstallDir($account, ['name' => $slug] + $appRow, $version);
            } else {
                $pkg = (string) ($manifest['npm']['package'] ?? '');
                if ($pkg === '') {
                    throw new \RuntimeException('npm manifest has no package.');
                }
                $this->log($installationId, 'info', "Installing npm package {$pkg}");
                $apps->npmInstall($account, ['name' => $slug] + $appRow, $pkg, $version);
            }
            $bin = (string) ($manifest['npm']['bin'] ?? '');
            $args = (array) ($manifest['npm']['args'] ?? []);
            if ($bin === '') {
                throw new \RuntimeException('npm manifest has no bin entry.');
            }
            $entry = $apps->writeLauncher($account, ['name' => $slug, 'port' => $port] + $appRow, 'node', $bin, $args);
        } elseif ($type === 'binary') {
            // Single-binary servers (Go/Rust releases): raw download or an
            // archive containing the binary (+ its data dirs) → +x → the
            // subprocess launcher execs it. Row runtime stays python: the
            // wrapper is what the supervisor executes, then it execs over.
            $runtime = 'python';
            $version = '3.11';
            $dl = $manifest['download'] ?? [];
            $url = $this->resolveDownloadUrl($application, $manifest);
            $binary = (string) ($manifest['app']['binary'] ?? '');
            $args = (array) ($manifest['app']['args'] ?? []);
            if ($url === '' || $binary === '') {
                throw new \RuntimeException('binary manifest needs download url + binary name.');
            }
            $format = (string) ($dl['format'] ?? 'file');
            $format = $format === 'zip' ? 'zip' : ($format === 'tar' ? 'tar' : 'file');
            $this->log($installationId, 'info', "Downloading {$url}");
            $archive = $this->download->download($url, (string) ($dl['sha256'] ?? ''), $format === 'file' ? 'bin' : ($format === 'zip' ? 'zip' : 'tar.gz'));
            $destRel = ltrim(str_replace($home, '', $workDir), '/');
            if ($format === 'file') {
                app('provisioning')->execute('installer.file.install', [
                    'account' => $username,
                    'archive' => $archive,
                    'dest' => $destRel,
                    'name' => $binary,
                    'executable' => '1',
                ]);
            } else {
                $this->log($installationId, 'info', "Extracting to {$workDir}");
                app('provisioning')->execute('installer.install', [
                    'account' => $username,
                    'archive' => $archive,
                    'dest' => $destRel,
                    'format' => $format,
                    'strip_root' => !empty($dl['strip_root']) ? '1' : '0',
                ]);
                app('provisioning')->execute('file.chmod', [
                    'account' => $username,
                    'path' => $destRel . '/' . $binary,
                    'mode' => '755',
                ]);
            }
            $this->download->cleanup($archive);
            $entry = $apps->writeLauncher($account, ['name' => $slug, 'port' => $port] + $appRow, 'binary', $binary, $args);
        } else {
            throw new \RuntimeException("Unsupported app type [{$type}].");
        }

        // Seed files (manifest app.seed_files {relpath: content}): starter
        // content some servers require (json-server db.json, docsify
        // index.html, gatus config.yaml). Paths confined under the work dir,
        // contents capped, written account-owned via file.write. {PORT} and
        // {HOST} expand to the app's allocated loopback port, plus the
        // public address placeholders ({domain}, {origin}, {url}, {urlpath})
        // so configs can carry correct external URLs (forge ROOT_URL).
        $scheme = (string) ($opts['scheme'] ?? 'https');
        $domain = (string) $opts['domain'];
        $siteUrl = $scheme . '://' . $domain . ($basePath === '/' ? '' : $basePath);
        // {HOST} resolves to the account's netns address when app-netns is
        // enabled (same rule as writeLauncher): seed files that bind (app.js
        // templates, jupyter config, gitea/forgejo app.ini) must listen on
        // the namespace address so the host-side nginx can proxy to them.
        // Fail closed (audit #20): seed configs that bind must use the
        // verified namespace address, never a loopback fallback.
        $bindHost = app(\Modules\Network\Services\AppNetnsService::class)->ensureHost($account);
        $seeds = $manifest['app']['seed_files'] ?? [];
        if (is_array($seeds)) {
            $files = app(\Modules\Files\Services\FileService::class);
            // Wizard credentials for config-file-seeded apps (notebook's
            // server config, radicale's htpasswd): {pass_hash} is bcrypt.
            $seedUser = (string) $aUser;
            $seedPass = (string) $aPass;
            $seedPassHash = $seedPass !== '' ? (string) password_hash($seedPass, PASSWORD_BCRYPT) : '';
            // sha256-crypt ($5$) for htpasswd stores that don't do bcrypt
            // (radicale's htpasswd_encryption=sha256).
            $seedPassSha256 = '';
            if ($seedPass !== '') {
                $c = crypt($seedPass, '$5$rounds=5000$' . substr(bin2hex(random_bytes(8)), 0, 16) . '$');
                $seedPassSha256 = (is_string($c) && str_starts_with($c, '$5$')) ? $c : '';
            }
            foreach ($seeds as $relPath => $content) {
                $relPath = trim((string) $relPath, '/');
                $content = str_replace(
                    ['{PORT}', '{HOST}', '{domain}', '{origin}', '{url}', '{urlpath}', '{user}', '{pass}', '{pass_hash}', '{pass_sha256}'],
                    [(string) $port, $bindHost, $domain, $scheme . '://' . $domain, $siteUrl, rtrim($basePath, '/') . '/', $seedUser, $seedPass, $seedPassHash, $seedPassSha256],
                    (string) $content
                );
                if ($relPath === '' || strlen($relPath) > 128 || str_contains($relPath, '..')
                    || !preg_match('#^[A-Za-z0-9._/-]+$#', $relPath) || strlen($content) > 65536
                ) {
                    throw new \RuntimeException("Invalid seed file [{$relPath}].");
                }
                $dir = dirname($relPath);
                if ($dir !== '.' && $dir !== '') {
                    // file.mkdir needs each parent to exist: create level by level.
                    $acc = 'apps/' . $slug;
                    foreach (explode('/', $dir) as $part) {
                        $acc .= '/' . $part;
                        $files->mkdir($username, $acc);
                    }
                }
                if (!$files->write($username, 'apps/' . $slug . '/' . $relPath, $content)) {
                    throw new \RuntimeException("Could not write seed file [{$relPath}].");
                }
            }
        }

        $appId = (int) app('db')->insert('applications', [
            'account_id' => (int) $account['id'],
            'name' => $slug,
            'runtime' => $runtime,
            'runtime_version' => $version,
            'entry_point' => $entry,
            'work_dir' => $workDir,
            'status' => 'stopped',
            'git_url' => null,
            'git_branch' => 'main',
            'port' => $port,
            'website_id' => $websiteId,
            'base_path' => $basePath,
            'env' => $this->resolveEnv($manifest, $port),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->log($installationId, 'info', "Registered {$runtime} app on 127.0.0.1:{$port}");

        // $domain/$siteUrl computed with the seeds above.
        $adminPath = (string) ($manifest['admin']['path'] ?? '');
        app('db')->update('ai_installations', [
            'application_id' => $appId,
            'path' => $workDir,
            'db_name' => '',
            'db_user' => '',
            'db_password' => '',
            'admin_user' => (string) ($opts['admin_user'] ?? ''),
            'site_url' => $siteUrl,
            'admin_url' => $adminPath !== '' ? $siteUrl . '/' . $adminPath : '',
            'config' => json_encode($this->safeConfig($opts)),
            'status' => 'completed',
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$installationId]);
        // Auto-start: the unit is registered and the mount is live, so
        // bring the supervised process up now instead of leaving it
        // stopped for a manual press in Manage. A start failure never
        // fails the install (the unit + files are ready; the user retries
        // from Manage) — it logs a warning with the exact next step.
        // start() only means the unit was accepted, so verify it is still
        // active after a settle (crash-loops trip systemd's backoff within
        // seconds) and quote the journal tail when it is not.
        $active = false;
        try {
            $appRow = app('db')->selectOne('SELECT * FROM `applications` WHERE `id` = ?', [$appId]);
            $started = is_array($appRow) && $apps->start($account, $appRow);
            $active = false;
            if ($started) {
                sleep(10);
                try {
                    $st = app('provisioning')->execute('app.status', ['account' => $username, 'name' => $slug]);
                    $active = trim((string) ($st['output'] ?? '')) === 'active';
                } catch (\Throwable $e) {
                    $active = false;
                }
            }
            if ($active) {
                // Mirror the controllers' Start convention ($ok → running):
                // service-layer starts otherwise leave the row stopped
                // until a sweep notices, and Manage shows a dead button
                // for a live process.
                app('db')->update('applications', ['status' => 'running', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$appId]);
                $this->log($installationId, 'info', "Started {$slug} on 127.0.0.1:{$port} ({$siteUrl})");
            } else {
                $tail = '';
                try {
                    $lg = app('provisioning')->execute('app.logs', ['account' => $username, 'name' => $slug]);
                    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) ($lg['output'] ?? '')))));
                    $tail = implode(' | ', array_slice($lines, -3));
                    $tail = mb_substr($tail, -400);
                } catch (\Throwable $e) {
                }
                $this->log($installationId, 'warning', "Installed but the process is not staying up — check Node.js/Python → Manage → Logs for {$slug} ({$siteUrl})" . ($tail !== '' ? " — {$tail}" : ''));
            }
        } catch (\Throwable $e) {
            $this->log($installationId, 'warning', 'Installed but could not start automatically (' . $e->getMessage() . ') — start it from Node.js/Python → Manage (' . $siteUrl . ')');
        }
        // Post-boot setup (setup.cli on app installs): the unit already
        // booted the app (forge databases migrate on first boot), so an
        // in-tree command can now finish what files alone cannot (forge
        // admin creation). Runs only when the process is up; a failure
        // warns (the driver leaves a recoverable state) but never fails
        // the install.
        $appCli = $manifest['setup']['cli'] ?? null;
        $appCliSteps = [];
        if (is_array($appCli)) {
            $appCliSteps = isset($appCli['script']) ? [$appCli] : (array_is_list($appCli) ? $appCli : []);
        }
        if ($appCliSteps !== [] && $active) {
            $this->runCliSetup($installationId, $username, 'apps/' . $slug, $appCliSteps, $this->setupPlaceholders(
                '', '', '', [], $manifest, (string) ($opts['title'] ?? ''), $aUser, $aPass, $aEmail,
                $siteUrl, $domain, $username, trim($basePath, '/'), $account, '84', $opts
            ) + [
                // App-type setup drivers may need the supervised process
                // address (statping/navidrome finish via an HTTP API on the
                // allocated port; the PHP install path never needs these).
                '{PORT}' => (string) $port,
                '{HOST}' => $bindHost,
            ], (string) ($application['name'] ?? 'application'));
            foreach ((array) ($manifest['setup']['cleanup'] ?? []) as $rel) {
                $rel = trim((string) $rel, '/');
                if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
                    continue;
                }
                try {
                    app('provisioning')->execute('directory.remove', [
                        'account' => $username,
                        'path' => 'apps/' . $slug . '/' . $rel,
                    ]);
                    $this->log($installationId, 'info', "Removed installer file {$rel}");
                } catch (\Throwable $e) {
                    $this->log($installationId, 'warning', "Could not remove installer file {$rel} (" . $e->getMessage() . ')');
                }
            }
        }
        // The app is now booted and the worker has had a full cycle; push
        // the proxy mount NOW so the worker's vhost references a port that is
        // actually listening (ordering mattered historically — a proxy pushed
        // before the app boots serves 502 until another regenerate happens).
        if ($active) {
            $this->refreshProxy((int) $websiteId, $installationId);
        } else {
            $this->refreshProxy((int) $websiteId, $installationId);
            $this->log($installationId, 'info', 'Reverse proxy mounted (app not yet active)');
        }
        $probe = app('db')->selectOne('SELECT `nginx_config` FROM `websites` WHERE `id` = ?', [$websiteId]);
        $this->log($installationId, 'info', 'post-install proxy check: stored=' . (strpos((string) ($probe['nginx_config'] ?? ''), 'proxy_pass') !== false ? 'YES' : 'NO') . ' app=' . $appId . ' active=' . ($active ? '1' : '0'));
        $this->log($installationId, 'info', 'Installation complete');
    }

    /**
     * Refresh an app-type install: remove + reinstall the package layer so
     * the latest compatible release lands (pip/npm resolve at install
     * time), then rewrite the launcher in case entry paths moved. The
     * applications row (port, mount, env) is untouched, so the proxy and
     * supervision keep working throughout.
     */
    private function updateApp(int $installationId, array $account, array $application, array $manifest, array $inst): void
    {
        $username = $account['username'];
        $home = $account['home_dir'] ?? "/home/{$username}";
        $type = (string) $manifest['type'];
        $appRow = null;
        if (!empty($inst['application_id'])) {
            $appRow = app('db')->selectOne(
                'SELECT * FROM `applications` WHERE `id` = ? AND `account_id` = ?',
                [(int) $inst['application_id'], (int) $account['id']]
            );
        }
        if ($appRow === null) {
            throw new \RuntimeException('Linked application is gone (uninstall + reinstall instead).');
        }
        $apps = app(\Modules\Applications\Services\AppService::class);
        $appRef = ['name' => $appRow['name']] + $appRow;

        if ($type === 'python-pip') {
            $pkg = (string) ($manifest['pip']['package'] ?? '');
            $version = \Modules\Applications\Services\AppService::normalizeVersion('python', (string) ($manifest['runtime']['version'] ?? '3.11'));
            $this->log($installationId, 'info', "Refreshing pip package {$pkg}");
            try {
                $apps->uninstallPackage($account, ['name' => $appRow['name'], 'runtime' => 'python'], $pkg);
            } catch (\Throwable $e) {
                $this->log($installationId, 'info', 'Uninstall skipped: ' . $e->getMessage());
            }
            $apps->installAppPackage($account, ['name' => $appRow['name'], 'runtime_version' => $version] + $appRef, $pkg);
            $apps->writeLauncher($account, $appRef, 'python', (string) ($manifest['pip']['script'] ?? $pkg), (array) ($manifest['pip']['args'] ?? []));
        } elseif ($type === 'binary') {
            $dl = $manifest['download'] ?? [];
            $url = $this->resolveDownloadUrl($application, $manifest);
            $binary = (string) ($manifest['app']['binary'] ?? '');
            if ($url === '' || $binary === '') {
                throw new \RuntimeException('binary manifest needs download url + binary name.');
            }
            $this->log($installationId, 'info', "Refreshing binary {$binary}");
            $format = (string) ($dl['format'] ?? 'file');
            $format = $format === 'zip' ? 'zip' : ($format === 'tar' ? 'tar' : 'file');
            $archive = $this->download->download($url, (string) ($dl['sha256'] ?? ''), $format === 'file' ? 'bin' : ($format === 'zip' ? 'zip' : 'tar.gz'));
            $destRel = ltrim(str_replace($home, '', (string) $inst['path']), '/');
            if ($format === 'file') {
                app('provisioning')->execute('installer.file.install', [
                    'account' => $username,
                    'archive' => $archive,
                    'dest' => $destRel,
                    'name' => $binary,
                    'executable' => '1',
                ]);
            } else {
                app('provisioning')->execute('installer.install', [
                    'account' => $username,
                    'archive' => $archive,
                    'dest' => $destRel,
                    'format' => $format,
                    'strip_root' => !empty($dl['strip_root']) ? '1' : '0',
                ]);
                app('provisioning')->execute('file.chmod', [
                    'account' => $username,
                    'path' => $destRel . '/' . $binary,
                    'mode' => '755',
                ]);
            }
            $this->download->cleanup($archive);
            $apps->writeLauncher($account, $appRef, 'binary', $binary, (array) ($manifest['app']['args'] ?? []));
        } elseif ($type === 'node-npm' || $type === 'node-archive') {
            $version = \Modules\Applications\Services\AppService::normalizeVersion('node', (string) ($manifest['runtime']['version'] ?? '22'));
            if ($type === 'node-archive') {
                $this->installArchive($installationId, $account, $application, $manifest, (string) $inst['path'], $home);
                $this->log($installationId, 'info', 'Refreshing npm dependencies');
                $apps->npmInstallDir($account, $appRef, $version);
            } else {
                $pkg = (string) ($manifest['npm']['package'] ?? '');
                $this->log($installationId, 'info', "Refreshing npm package {$pkg}");
                app('provisioning')->execute('directory.remove', [
                    'account' => $username,
                    'path' => 'apps/' . $appRow['name'] . '/node_modules',
                ]);
                $apps->npmInstall($account, $appRef, $pkg, $version);
            }
            $apps->writeLauncher($account, $appRef, 'node', (string) ($manifest['npm']['bin'] ?? ''), (array) ($manifest['npm']['args'] ?? []));
        }
        $this->refreshProxy((int) ($appRow['website_id'] ?? 0), $installationId);
    }


    /**
     * Resolve manifest `app.env` (KEY => value with {PORT}/{HOST}
     * placeholders) into stored lines. Same strict charset the agent
     * enforces, so stored rows can never smuggle a bad pair later.
     */
    private function resolveEnv(array $manifest, int $port): ?string
    {
        $map = $manifest['app']['env'] ?? [];
        if (!is_array($map) || $map === []) {
            return null;
        }
        $lines = [];
        foreach ($map as $k => $v) {
            $k = trim((string) $k);
            $v = str_replace(['{PORT}', '{HOST}'], [(string) $port, '127.0.0.1'], (string) $v);
            $v = trim($v);
            if ($k === '' || strlen($k) > 64
                || !preg_match('/^[A-Z0-9_]+$/', $k) || !preg_match('/^[A-Za-z]/', $k)
                || $v === '' || strlen($v) > 256 || !preg_match('/^[A-Za-z0-9_.:\/-]+$/', $v)
            ) {
                throw new \RuntimeException("Invalid app env entry [{$k}].");
            }
            $lines[] = "{$k}={$v}";
        }
        return implode("\n", array_unique($lines));
    }


    /**
     * Regenerate + push the mount website's vhost (proxy locations come
     * from the generator). Tolerant: a vhost failure must not fail an
     * otherwise complete install — it is logged for the admin instead.
     */
    private function refreshProxy(int $websiteId, int $installationId): void
    {
        try {
            $site = app('db')->selectOne('SELECT * FROM `websites` WHERE `id` = ?', [$websiteId]);
            $hasApp = false;
            if ($site !== null && (int) ($site['id'] ?? 0) > 0) {
                $row = app('db')->selectOne('SELECT `id` FROM `applications` WHERE `website_id` = ? AND `port` IS NOT NULL LIMIT 1', [((int) $site['id'])]);
                $hasApp = $row !== null;
            }
            $account = $site !== null ? app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $site['account_id']]) : null;
            $iso = app(\Modules\Core\Services\NginxIsolationService::class);
            $host = $site !== null ? (string) ($site['label'] ?? '') : '';

            $attempts = $hasApp ? 3 : 1;
            for ($i = 0; $i < $attempts; $i++) {
                app(\Modules\Websites\Services\PrivacyService::class)->regenerate($websiteId);
                $after = app('db')->selectOne('SELECT `nginx_config` FROM `websites` WHERE `id` = ?', [$websiteId]);
                $storedOk = $after !== null && strpos((string) $after['nginx_config'], 'proxy_pass') !== false;
                $diskOk = true;
                if ($account !== null && $iso->isEnabled($account)) {
                    $disk = @file_get_contents('/home/' . $account['username'] . '/nginx/conf.d/' . $host . '.conf');
                    $diskOk = is_string($disk) && strpos($disk, 'proxy_pass') !== false;
                    if (!$storedOk || !$diskOk) {
                        $port = $iso->portFor((int) $account['id']);
                        if ($port !== null) {
                            $iso->rewriteVhosts($account, $port);
                            $iso->startWorker($account);
                            $disk = @file_get_contents('/home/' . $account['username'] . '/nginx/conf.d/' . $host . '.conf');
                            $diskOk = is_string($disk) && strpos($disk, 'proxy_pass') !== false;
                        }
                    }
                }
                if ($storedOk && $diskOk) {
                    if ($i > 0) {
                        $this->log($installationId, 'info', 'Proxy mount converged on attempt ' . ($i + 1));
                    }
                    break;
                }
                if ($hasApp && $i < $attempts - 1) {
                    sleep(2);
                }
                if ($hasApp && $i === $attempts - 1) {
                    $this->log($installationId, 'warning', 'Proxy mount still missing after ' . $attempts . ' attempts (stored=' . ($storedOk ? 'YES' : 'NO') . ' disk=' . ($diskOk ? 'YES' : 'NO') . ')');
                }
            }
            $this->log($installationId, 'info', 'Reverse proxy mounted.');
        } catch (\Throwable $e) {
            $this->log($installationId, 'error', 'Proxy mount failed (re-save the website to retry): ' . $e->getMessage());
        }
    }

    /**
     * Update an installed application. PHP types re-download + extract-over
     * (existing behavior, config + content preserved). App types refresh
     * their package layer instead (pip/npm reinstall latest): user content
     * lives outside the package dirs, so nothing is lost.
     */
    public function update(int $installationId): void
    {
        $inst = app('db')->selectOne('SELECT * FROM `ai_installations` WHERE `id` = ?', [$installationId]);
        if ($inst === null) {
            throw new \RuntimeException('Installation not found.');
        }
        $application = $this->catalog->find($inst['application_slug']);
        if ($application === null) {
            throw new \RuntimeException('Application not found in catalog.');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $inst['account_id']]);
        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'appinstaller');
        $manifest = $this->catalog->manifest($application);
        $home = $account['home_dir'] ?? '/home/' . $account['username'];

        if (self::isAppType($manifest)) {
            app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications');
            $this->updateApp($installationId, $account, $application, $manifest, $inst);
            app('db')->update('ai_installations', [
                'version' => $application['version'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$installationId]);
            $this->log($installationId, 'info', 'Update complete');
            return;
        }

        $dl = $manifest['download'] ?? [];
        $url = $this->resolveDownloadUrl($application, $manifest);
        $sha = (string) ($dl['sha256'] ?? '');
        $format = ($dl['format'] ?? 'tar') === 'zip' ? 'zip' : 'tar';
        $ext = $format === 'zip' ? 'zip' : 'tar.gz';
        if ($url === '') {
            throw new \RuntimeException('Application has no download source.');
        }

        $this->log($installationId, 'info', "Updating to latest ({$url})");
        $archive = $this->download->download($url, $sha, $ext);

        // Extract-over the existing install (confined, slip-safe, owned by account).
        $destRel = ltrim(str_replace($home, '', (string) $inst['path']), '/');
        if ($format === 'file') {
            // Single-file refresh: reinstall over the stored file path.
            $dir = dirname($destRel) === '.' ? '' : dirname($destRel);
            app('provisioning')->execute('installer.file.install', [
                'account' => $account['username'],
                'archive' => $archive,
                'dest' => $dir,
                'name' => basename($destRel),
            ]);
        } else {
            // Extract-over merge: same strip rule as installs, so
            // single-top-dir archives refresh in place instead of nesting
            // one level deeper on every update.
            app('provisioning')->execute('installer.update', [
                'account' => $account['username'],
                'archive' => $archive,
                'dest' => $destRel,
                'format' => $format,
                'strip_root' => !empty($dl['strip_root']) ? '1' : '0',
            ]);
        }
        $this->download->cleanup($archive);

        app('db')->update('ai_installations', [
            'version' => $application['version'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$installationId]);
        $this->log($installationId, 'info', 'Update complete');
    }
}
