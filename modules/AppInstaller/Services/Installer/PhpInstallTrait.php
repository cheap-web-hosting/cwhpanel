<?php

declare(strict_types=1);

namespace Modules\AppInstaller\Services\Installer;

/**
 * @internal Extracted from InstallerService (concern group).
 */
trait PhpInstallTrait
{


    private function runInstall(int $installationId, array $account, array $application, array $opts): void
    {
        $username = $account['username'];
        $home = $account['home_dir'] ?? "/home/{$username}";
        $manifest = $this->catalog->manifest($application);
        $docroot = rtrim((string) ($opts['docroot'] ?? $home . '/public_html'), '/');
        $directory = trim((string) ($opts['directory'] ?? ''), '/');
        $this->log($installationId, 'info', "Starting install of {$application['name']} {$application['version']}");

        // 1. Download the package.
        $dl = $manifest['download'] ?? [];
        $url = $this->resolveDownloadUrl($application, $manifest);
        $sha = (string) ($dl['sha256'] ?? '');
        $format = (string) ($dl['format'] ?? 'tar');
        $format = $format === 'zip' ? 'zip' : ($format === 'file' ? 'file' : 'tar');
        $ext = $format === 'zip' ? 'zip' : ($format === 'file' ? 'bin' : 'tar.gz');
        if ($url === '') {
            throw new \RuntimeException('Application has no download source.');
        }

        $this->log($installationId, 'info', "Downloading {$url}");
        $archive = $this->download->download($url, $sha, $ext);

        // 2. Extract + chown via the agent (slip-safe, account-confined) —
        // or place a single file (single-file PHP apps, no archive).
        $destAbs = $docroot . ($directory !== '' ? '/' . $directory : '');
        $destRel = ltrim(str_replace($home, '', $destAbs), '/');
        if ($format === 'file') {
            $fileName = (string) ($manifest['file']['name'] ?? '');
            if ($fileName === '') {
                $this->download->cleanup($archive);
                throw new \RuntimeException('Single-file manifest needs file.name.');
            }
            $this->log($installationId, 'info', "Placing {$fileName} in {$destAbs}");
            app('provisioning')->execute('installer.file.install', [
                'account' => $username,
                'archive' => $archive,
                'dest' => $destRel,
                'name' => $fileName,
            ]);
            $this->download->cleanup($archive);
        } else {
            $this->log($installationId, 'info', "Extracting to {$destAbs}");
            $installArgs = [
                'account' => $username,
                'archive' => $archive,
                'dest' => $destRel,
                'format' => $format,
                'strip_root' => !empty($dl['strip_root']) ? '1' : '0',
            ];
            if (!empty($dl['strip_dir'])) {
                $installArgs['strip_dir'] = (string) $dl['strip_dir'];
            }
            app('provisioning')->execute('installer.install', $installArgs);
            $this->download->cleanup($archive);

            // Wrapper releases (PrestaShop): the outer archive holds a nested
            // app zip; extract it over the wrapper so the real tree is live.
            $nested = (string) ($dl['nested'] ?? '');
            if ($nested !== '' && !str_contains($nested, '..') && preg_match('#^[A-Za-z0-9._/-]+$#', $nested)) {
                $nestedFormat = str_ends_with($nested, '.tar.gz') ? 'tar' : 'zip';
                $this->log($installationId, 'info', 'Unpacking nested application archive.');
                app('provisioning')->execute('installer.extract', [
                    'account' => $username,
                    'archive' => ltrim($destRel . '/' . $nested, '/'),
                    'dest' => $destRel,
                    'format' => $nestedFormat,
                ]);
            }
        }

        // 3. Database (if required).
        $dbName = ''; $dbUser = ''; $dbPass = '';
        if ($manifest['database']['required'] ?? false) {
            $dbName = (string) ($opts['db_name'] ?? ($username . '_' . substr(hash('sha256', uniqid('', true)), 0, 8)));
            $dbUser = (string) ($opts['db_user'] ?? substr($dbName, 0, 16));
            $dbPass = bin2hex(random_bytes(12));
            $db = app(\Modules\Databases\Services\DatabaseService::class);
            $db->create($dbName);
            $db->createUser($dbUser, $dbPass);
            $db->grantAll($dbUser, $dbName);
            $this->log($installationId, 'info', "Created database {$dbName}");
        }

        // 4. Generate + write config, then run template setup.
        // The gate covers file-writing configs (wp/phpmyadmin/roundcube…)
        // AND helper-owned setups (dokuwiki writes its own conf files,
        // matomo its own INI via its Config class).
        $conf = $manifest['config'] ?? [];
        $confFile = (string) ($conf['file'] ?? '');
        $template = (string) ($conf['template'] ?? '');
        $domain = (string) ($opts['domain'] ?? '');
        $siteDir = $directory !== '' ? '/' . $directory : '';
        $siteUrl = ($opts['scheme'] ?? 'https') . '://' . $domain . $siteDir;
        if ($confFile !== '' || !empty($conf['files'])
            || in_array($template, ['dokuwiki', 'matomo', 'drupal', 'piwigo'], true)
            || isset($manifest['setup']['cli']) || isset($manifest['setup']['sql']) || isset($manifest['setup']['files'])
        ) {
            // Panel-written config file(s). Helper-owned formats (dokuwiki
            // conf files, matomo INI, grav account yaml) skip this and are
            // written by their own helpers below.
            $configTargets = [];
            if ($confFile !== '' && !in_array($template, ['dokuwiki', 'matomo', 'drupal', 'piwigo'], true)) {
                $configTargets[$confFile] = $template !== '' ? $template : 'generic';
            }
            foreach ((array) ($conf['files'] ?? []) as $rel => $tpl) {
                $rel = trim((string) $rel, '/');
                if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
                    continue;
                }
                // Allowlist: only known app config templates here.
                if (!in_array((string) $tpl, ['opencart', 'opencart-admin', 'joomla', 'phpmyadmin', 'roundcube', 'homer', 'generic'], true)) {
                    continue;
                }
                $configTargets[$rel] = (string) $tpl;
            }
            if ($configTargets !== []) {
                $values = [
                    'db_name' => $dbName,
                    'db_user' => $dbUser,
                    'db_password' => $dbPass,
                    'table_prefix' => (string) ($conf['prefix'] ?? ($manifest['database']['prefix'] ?? '')),
                    'db_host' => (string) env('DB_HOST', '127.0.0.1'),
                    'domain' => $domain,
                    'site_url' => $siteUrl,
                    'server' => ($opts['scheme'] ?? 'https') . '://' . $domain,
                    'scheme' => (string) ($opts['scheme'] ?? 'https'),
                    'docroot' => $destAbs,
                    'app_version' => (string) ($application['version'] ?? ''),
                ];
                // Salts/keys (WP) + generic per-app secrets.
                foreach (['auth_key','secure_auth_key','logged_in_key','nonce_key','auth_salt','secure_auth_salt','logged_in_salt','nonce_salt'] as $k) {
                    $values[$k] = bin2hex(random_bytes(32));
                }
                $values['blowfish_secret'] = bin2hex(random_bytes(16)); // 32 chars
                $values['des_key'] = bin2hex(random_bytes(12));         // roundcube 24 chars
                $values['secret'] = bin2hex(random_bytes(32));
                foreach ($configTargets as $rel => $tpl) {
                    $content = $this->generateConfig($tpl, $application, $values, $manifest);
                    $confRel = ltrim(str_replace($home, '', $destAbs . '/' . $rel), '/');
                    app('provisioning')->execute('installer.config', [
                        'account' => $username,
                        'path' => $confRel,
                        'content' => $content,
                    ]);
                    $this->log($installationId, 'info', "Wrote {$rel}");
                }
            }

            // 4b. WordPress/Matomo installs get the performance/compat
            // module set on the site's PHP master, in ONE master rewrite
            // (a single restart — N sequential php.ini.set reloads trip
            // systemd's start limit and strand the master, bitten live).
            // mysqli is managed (ships inside mysqlnd: link only, never
            // dnf-install — the agent rightly rejects installModule for it);
            // the rest fall back to a system-wide provide when the version
            // was provisioned before the whole-set change.
            $setup = is_array($opts['setup'] ?? null) ? $opts['setup'] : [];
            $domain = (string) ($opts['domain'] ?? '');
            $siteDir = $directory !== '' ? '/' . $directory : '';
            $siteUrl = ($opts['scheme'] ?? 'https') . '://' . $domain . $siteDir;
            $sTitle = trim((string) ($setup['title'] ?? ''));
            $sUser = trim((string) ($setup['admin_user'] ?? $opts['admin_user'] ?? ''));
            $sPass = (string) ($setup['admin_password'] ?? '');
            $sEmail = trim((string) ($setup['admin_email'] ?? ''));
            $haveSetupCreds = $sTitle !== '' && $sUser !== '' && $sPass !== '' && $sEmail !== '';
            // Credential-free setups (setup.no_creds): tools with no login
            // and no wizard (phpSysInfo's ini seed) run their files/cli
            // steps without wizard values. Such manifests must not
            // interpolate wizard placeholders (asserted in the suite).
            $canSetup = $haveSetupCreds || !empty($manifest['setup']['no_creds']);
            $destRel = ltrim(str_replace($home, '', $destAbs), '/');

            // Mount website for this docroot (single lookup reused below).
            $setupSite = app('db')->selectOne(
                'SELECT `id`, `php_version`, `php_settings` FROM `websites`
                  WHERE `account_id` = ? AND `document_root` = ? AND `status` = ?
                  ORDER BY `updated_at` DESC, `id` DESC LIMIT 1',
                [(int) $account['id'], $docroot, 'active']
            );
            $sitePhpId = \Modules\Core\Services\PhpVersionsService::id((string) ($setupSite['php_version'] ?? '84'));
            $sitePhpLabel = \Modules\Core\Services\PhpVersionsService::label($sitePhpId);
            // Clean numeric version for comparisons: label() appends a
            // "(system)" suffix for 8.4 which breaks version_compare.
            $sitePhpVer = substr($sitePhpId, 0, 1) . '.' . substr($sitePhpId, 1, 1);

            // PHP range gate for recipe setups (cli/sql): apps like
            // Nextcloud 35 refuse to run below 8.3, and ownCloud 10 caps at
            // < 8.0. Never auto-flip the site's version (that is production
            // config) — log the exact fix and leave files+DB ready instead.
            $setupBlocked = null;
            $needPhp = (string) ($manifest['requirements']['php_min'] ?? '');
            $maxPhp = (string) ($manifest['requirements']['php_max'] ?? '');
            $phpInRange = ($needPhp === '' || version_compare($sitePhpVer, $needPhp, '>='))
                && ($maxPhp === '' || version_compare($sitePhpVer, $maxPhp, '<='));
            // Per-directory PHP auto-pin: when the SITE's version is outside
            // the app's range but the install lives in a SUBDIRECTORY, pin
            // just that subdir to the best installed in-range version (the
            // multi-PHP panel can serve it without touching production).
            // Root installs keep the gate below — flipping the whole site's
            // version silently would strand whatever already lives there.
            $pinPhpId = null;
            $hasRecipe = isset($manifest['setup']['cli']) || isset($manifest['setup']['sql'])
                || in_array($template, ['wp', 'dokuwiki', 'matomo', 'drupal', 'piwigo'], true);
            if (!$phpInRange && $hasRecipe && $directory !== '' && (int) ($setupSite['id'] ?? 0) > 0) {
                $pinPhpId = $this->compatiblePhpId($needPhp, $maxPhp);
                if ($pinPhpId !== null && $pinPhpId !== $sitePhpId) {
                    try {
                        // Manifest-declared PHP settings (php_settings) ride
                        // the auto-pin: apps that need an ini value the stock
                        // default breaks (Dolphin 7.4 refuses to render with
                        // allow_url_include On) get it without touching
                        // production. Unknown keys are dropped by
                        // normalizeSettings, so this is fail-closed.
                        $pinSettings = [];
                        if (isset($manifest['php_settings']) && is_array($manifest['php_settings'])) {
                            $pinSettings = app(\Modules\Websites\Services\PhpDirService::class)->normalizeSettings($manifest['php_settings']);
                        }
                        app(\Modules\Websites\Services\PhpDirService::class)->save(
                            (int) $setupSite['id'],
                            $directory,
                            \Modules\Core\Services\PhpVersionsService::label($pinPhpId),
                            $pinSettings,
                            true
                        );
                        $this->log(
                            $installationId,
                            'info',
                            "Pinned /{$directory}/ to PHP " . \Modules\Core\Services\PhpVersionsService::label($pinPhpId)
                            . " ({$application['name']} needs"
                            . ($needPhp !== '' ? " >= {$needPhp}" : '')
                            . ($maxPhp !== '' ? " <= {$maxPhp}" : '')
                            . "); the rest of the site is untouched."
                        );
                    } catch (\Throwable $e) {
                        $pinPhpId = null;
                    }
                } else {
                    $pinPhpId = null;
                }
            }
            // Effective version for setup: the pin when one landed, else the site's.
            $effPhpId = $pinPhpId ?? $sitePhpId;
            $effPhpVer = substr($effPhpId, 0, 1) . '.' . substr($effPhpId, 1, 1);
            $effPhpLabel = \Modules\Core\Services\PhpVersionsService::label($effPhpId);
            if (($needPhp !== '' && version_compare($effPhpVer, $needPhp, '<'))
                || ($maxPhp !== '' && version_compare($effPhpVer, $maxPhp, '>'))
            ) {
                if ($needPhp !== '' && version_compare($effPhpVer, $needPhp, '<')) {
                    $setupBlocked = "needs PHP >= {$needPhp} but this website runs PHP {$effPhpLabel} — switch it under Websites → PHP Settings, then reinstall (files + database are ready).";
                } else {
                    $setupBlocked = "needs PHP <= {$maxPhp} but this website runs PHP {$effPhpLabel} — switch it under Websites → PHP Settings, then reinstall (files + database are ready).";
                }
                if ($pinPhpId === null && $directory !== '' && $hasRecipe) {
                    $setupBlocked .= ' (No installed PHP version satisfies the range — add one under Server → PHP Versions.)';
                }
            }
            // Database server floor (Drupal 11 needs MariaDB >= 10.6).
            $dbMin = (string) ($manifest['requirements']['db_min'] ?? '');
            if ($setupBlocked === null && $dbMin !== '') {
                try {
                    $serverVersion = (string) app('db')->scalar('SELECT VERSION()');
                    if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $serverVersion, $m)
                        && version_compare($m[1], $dbMin, '<')
                    ) {
                        $setupBlocked = "needs MariaDB/MySQL >= {$dbMin} but this server runs {$serverVersion} — upgrade the database server, then reinstall (files + database are ready).";
                    }
                } catch (\Throwable $e) {
                    // Version probe failure must not block installs.
                }
            }

            // Any DB-backed install needs the data layer linked (mysqli is
            // base-linked; the rest join in one master rewrite). Also run for
            // ANY manifest that declares required extensions: a flat-file app
            // (WonderCMS: zip) 500s at runtime otherwise.
            $reqExtensions = (array) ($manifest['requirements']['extensions'] ?? []);
            if (in_array($template, ['wp', 'matomo'], true) || !empty($manifest['database']['required']) || $reqExtensions !== []) {
                try {
                    $site = $setupSite;
                    $ver = $effPhpId;
                    $phpSvc = app(\Modules\Core\Services\PhpVersionsService::class);
                    $want = ['mysqli', 'zip', 'imagick', 'apcu', 'memcached', 'sodium'];
                    // Plus whatever the manifest demands (Firefly needs
                    // bcmath on the pinned master — the base list above
                    // alone 500s it). Only manageable modules qualify.
                    foreach ((array) ($manifest['requirements']['extensions'] ?? []) as $reqMod) {
                        $reqMod = strtolower(trim((string) $reqMod));
                        if ($reqMod !== '' && !in_array($reqMod, $want, true)
                            && in_array($reqMod, \Modules\Core\Services\PhpVersionsService::MODULES, true)
                        ) {
                            $want[] = $reqMod;
                        }
                    }
                    // mysqli is base-provided in every pool (it ships inside mysqlnd
                    // and is NOT a manageable link), so keep it out of the
                    // install set — nothing to do for it.
                    $link = array_values(array_diff($want, ['mysqli']));
                    $provided = array_map('strtolower', $phpSvc->loadedModules($ver));
                    foreach ($link as $mod) {
                        if (in_array($mod, $provided, true)) {
                            continue;
                        }
                        try {
                            $phpSvc->installModule($ver, $mod);
                        } catch (\Throwable $e) {
                            $this->log($installationId, 'warning', "Module {$mod} could not be provided: " . $e->getMessage());
                        }
                    }
                    $settings = null;
                    if (is_string($site['php_settings'] ?? null) && $site['php_settings'] !== '') {
                        $decoded = json_decode((string) $site['php_settings'], true);
                        $settings = is_array($decoded) ? $decoded : null;
                    }
                    $phpSvc->ensurePool($account, $ver, $settings);
                    $this->log($installationId, 'info', 'Enabled PHP modules: ' . implode(', ', $want));
                } catch (\Throwable $e) {
                    $this->log($installationId, 'warning', 'Could not enable PHP modules automatically: ' . $e->getMessage());
                }
            }

            if ($template === 'wp') {
                // 4c. Unattended WordPress setup: run WP's own wp_install()
                // as the account user (agent op, idempotent — ALREADY when the
                // tables exist). Credentials arrive transiently via the job
                // payload; installs queued before that carry none and keep the
                // old files+DB behavior (finish in the browser).
                if ($haveSetupCreds) {
                    try {
                        $r = app('provisioning')->execute('installer.wordpress.install', [
                            'account' => $username,
                            'dest' => $destRel,
                            'title' => mb_substr($sTitle, 0, 200),
                            'admin_user' => $sUser,
                            'admin_password' => $sPass,
                            'admin_email' => $sEmail,
                            'site_url' => $siteUrl,
                        ]);
                        $out = trim((string) ($r['output'] ?? ''));
                        if (str_contains($out, 'ALREADY')) {
                            $this->log($installationId, 'info', 'WordPress was already set up (tables present) — left untouched.');
                        } else {
                            $this->log($installationId, 'info', "WordPress is ready — log in with '{$sUser}' at {$siteUrl}/wp-admin/");
                        }
                    } catch (\Throwable $e) {
                        $this->log($installationId, 'warning', 'Automatic setup did not finish (' . $e->getMessage() . ') — open the site URL to complete the WordPress wizard.');
                    }
                } else {
                    $this->log($installationId, 'info', 'No setup credentials queued — open the site URL to finish the WordPress wizard.');
                }
            } elseif ($template === 'dokuwiki') {
                // 4c. Unattended DokuWiki setup: replicate install.php for a
                // closed wiki (local.php + bcrypt users.auth.php + closed
                // acl.auth.php + plugins.local.php, install.php removed).
                // No database involved.
                if ($haveSetupCreds) {
                    try {
                        $r = app('provisioning')->execute('installer.dokuwiki.install', [
                            'account' => $username,
                            'dest' => $destRel,
                            'title' => mb_substr($sTitle, 0, 200),
                            'admin_user' => strtolower($sUser),
                            'admin_password' => $sPass,
                            'admin_email' => $sEmail,
                        ]);
                        $out = trim((string) ($r['output'] ?? ''));
                        if (str_contains($out, 'ALREADY')) {
                            $this->log($installationId, 'info', 'DokuWiki was already set up (users exist) — left untouched.');
                        } else {
                            $this->log($installationId, 'info', "DokuWiki is ready — log in with '" . strtolower($sUser) . "' at {$siteUrl}/doku.php");
                        }
                    } catch (\Throwable $e) {
                        $this->log($installationId, 'warning', 'Automatic setup did not finish (' . $e->getMessage() . ') — open install.php on the site URL to finish.');
                    }
                } else {
                    $this->log($installationId, 'info', 'No setup credentials queued — open install.php on the site URL to finish.');
                }
            } elseif ($template === 'matomo') {
                // 4c. Unattended Matomo setup: the helper replays the
                // Installation plugin (config + tables + superuser + first
                // website + security files) with Matomo's own classes.
                // The panel-managed DB above is reused (no second database).
                if ($haveSetupCreds && $dbName !== '') {
                    try {
                        $r = app('provisioning')->execute('installer.matomo.install', [
                            'account' => $username,
                            'dest' => $destRel,
                            'db_host' => (string) env('DB_HOST', '127.0.0.1'),
                            'db_name' => $dbName,
                            'db_user' => $dbUser,
                            'db_pass' => $dbPass,
                            'prefix' => (string) ($conf['prefix'] ?? 'matomo_'),
                            'title' => mb_substr($sTitle, 0, 200),
                            'admin_user' => $sUser,
                            'admin_password' => $sPass,
                            'admin_email' => $sEmail,
                            'site_url' => $siteUrl,
                            'domain' => $domain,
                        ]);
                        $out = trim((string) ($r['output'] ?? ''));
                        if (str_contains($out, 'ALREADY')) {
                            $this->log($installationId, 'info', 'Matomo was already set up — left untouched.');
                        } else {
                            $this->log($installationId, 'info', "Matomo is ready — log in with '{$sUser}' at {$siteUrl}/index.php");
                        }
                    } catch (\Throwable $e) {
                        $this->log($installationId, 'warning', 'Automatic setup did not finish (' . $e->getMessage() . ') — open the site URL to finish the Matomo wizard.');
                    }
                } else {
                    $this->log($installationId, 'info', 'No setup credentials queued — open the site URL to finish the Matomo wizard.');
                }
            } elseif ($template === 'drupal') {
                // 4c. Unattended Drupal setup: a root-owned helper drives
                // Drupal's installer with the MySQL driver (stock `dr
                // install` forces SQLite + a fixed admin). Idempotent.
                if ($setupBlocked !== null) {
                    $this->log($installationId, 'warning', 'Automatic setup skipped (' . $setupBlocked . ')');
                } elseif ($haveSetupCreds && $dbName !== '') {
                    try {
                        $r = app('provisioning')->execute('installer.drupal.install', [
                            'account' => $username,
                            'dest' => $destRel,
                            'db_host' => (string) env('DB_HOST', '127.0.0.1'),
                            'db_name' => $dbName,
                            'db_user' => $dbUser,
                            'db_pass' => $dbPass,
                            'admin_user' => $sUser,
                            'admin_password' => $sPass,
                            'admin_email' => $sEmail,
                            'title' => mb_substr($sTitle, 0, 200),
                        ]);
                        $out = trim((string) ($r['output'] ?? ''));
                        if (str_contains($out, 'ALREADY')) {
                            $this->log($installationId, 'info', 'Drupal was already set up — left untouched.');
                        } else {
                            $this->log($installationId, 'info', "Drupal is ready — log in with '{$sUser}' at {$siteUrl}/user/login");
                        }
                    } catch (\Throwable $e) {
                        $this->log($installationId, 'warning', 'Automatic setup did not finish (' . $e->getMessage() . ') — open the site URL to finish the Drupal installer.');
                    }
                } else {
                    $this->log($installationId, 'info', 'No setup credentials queued — open the site URL to finish the Drupal installer.');
                }
            } elseif ($template === 'piwigo') {
                // 4c. Unattended Piwigo setup: a root-owned helper supplies
                // the web installer's POST form and runs it non-interactively.
                if ($setupBlocked !== null) {
                    $this->log($installationId, 'warning', 'Automatic setup skipped (' . $setupBlocked . ')');
                } elseif ($haveSetupCreds && $dbName !== '') {
                    try {
                        $r = app('provisioning')->execute('installer.piwigo.install', [
                            'account' => $username,
                            'dest' => $destRel,
                            'db_host' => (string) env('DB_HOST', '127.0.0.1'),
                            'db_name' => $dbName,
                            'db_user' => $dbUser,
                            'db_pass' => $dbPass,
                            'prefix' => (string) ($conf['prefix'] ?? ($manifest['database']['prefix'] ?? 'piwigo_')),
                            'admin_user' => $sUser,
                            'admin_password' => $sPass,
                            'admin_email' => $sEmail,
                            'domain' => $domain,
                        ]);
                        $out = trim((string) ($r['output'] ?? ''));
                        if (str_contains($out, 'ALREADY')) {
                            $this->log($installationId, 'info', 'Piwigo was already set up — left untouched.');
                        } else {
                            $this->log($installationId, 'info', "Piwigo is ready — log in with '{$sUser}' at {$siteUrl}/admin.php");
                        }
                    } catch (\Throwable $e) {
                        $this->log($installationId, 'warning', 'Automatic setup did not finish (' . $e->getMessage() . ') — open the site URL to finish the Piwigo installer.');
                    }
                } else {
                    $this->log($installationId, 'info', 'No setup credentials queued — open the site URL to finish the Piwigo installer.');
                }
            }

            // 4d. Manifest-driven file setup (setup.files): write text files
            // ({placeholder}-interpolated, account-owned) the app reads at
            // boot — Grav account YAML, Lychee .env, etc.
            $setupFiles = $manifest['setup']['files'] ?? null;
            if (is_array($setupFiles) && $canSetup) {
                $map = $this->setupPlaceholders($dbName, $dbUser, $dbPass, $conf, $manifest, $sTitle, $sUser, $sPass, $sEmail, $siteUrl, $domain, $username, $directory, $account, $effPhpId, $opts);
                foreach ($setupFiles as $relTpl => $contentTpl) {
                    // Placeholders interpolate in the PATH too (e.g.
                    // user/accounts/{user}.yaml -> gravadmin.yaml).
                    $rel = strtr((string) $relTpl, $map);
                    $rel = trim($rel, '/');
                    if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
                        continue;
                    }
                    $content = strtr((string) $contentTpl, $map);
                    $confRel = ltrim(str_replace($home, '', $destAbs . '/' . $rel), '/');
                    try {
                        // Files under a fresh subdir (Elgg's throwaway
                        // elgg-setup/): the write op requires an existing
                        // parent, so mkdir it first. Best-effort.
                        $parent = dirname($confRel);
                        if ($parent !== '' && $parent !== '.' && $parent !== $confRel) {
                            try {
                                app('provisioning')->execute('directory.create', ['path' => $home . '/' . $parent]);
                            } catch (\Throwable $e) {
                            }
                        }
                        app('provisioning')->execute('installer.config', [
                            'account' => $username,
                            'path' => $confRel,
                            'content' => $content,
                        ]);
                        // directory.create mkdirs as ROOT: converge the top
                        // segment of the written path back to the account
                        // (recursive), or the app can't write beside its own
                        // config (bitten live: SnappyMail's data/_data_/
                        // landed root-owned and cache/ creation 500d every
                        // hit). Harmless for already-owned trees.
                        $topSeg = explode('/', $rel)[0];
                        if ($topSeg !== '') {
                            try {
                                $topRel = $destRel === '' ? $topSeg : $destRel . '/' . $topSeg;
                                app('provisioning')->execute('directory.chown', [
                                    'owner' => $username,
                                    'path' => $home . '/' . $topRel,
                                ]);
                            } catch (\Throwable $e) {
                            }
                        }
                        $this->log($installationId, 'info', "Wrote {$rel}");
                    } catch (\Throwable $e) {
                        $this->log($installationId, 'warning', "Could not write {$rel} (" . $e->getMessage() . ')');
                    }
                }
            }

            // 4d. Manifest-driven CLI setup (setup.cli): the tree's own
            // installer binary (occ, maintenance/install.php, index_cli.php)
            // with {placeholders} interpolated into discrete argv (no shell)
            // and executed as the account user. Independent of the template
            // branches above.
            $cli = $manifest['setup']['cli'] ?? null;
            $cliSteps = [];
            if (is_array($cli)) {
                $cliSteps = isset($cli['script']) ? [$cli] : (array_is_list($cli) ? $cli : []);
            }
            if ($cliSteps !== []) {
                if ($setupBlocked !== null) {
                    $this->log($installationId, 'warning', 'Automatic setup skipped (' . $setupBlocked . ')');
                } elseif ($canSetup) {
                    $this->runCliSetup($installationId, $username, $destRel, $cliSteps, $this->setupPlaceholders(
                        $dbName, $dbUser, $dbPass, $conf, $manifest, $sTitle, $sUser, $sPass, $sEmail,
                        $siteUrl, $domain, $username, $directory, $account, $effPhpId, $opts
                    ), (string) ($application['name'] ?? 'application'));
                } else {
                    $this->log($installationId, 'info', 'No setup credentials queued — open the site URL to finish setup in the browser.');
                }
            }

            // 4d4. Post-setup SQL updates (Drupal: the CLI creates a fixed
            // 'admin' account; rename it to the chosen user/email).
            $sqlPost = $manifest['setup']['sql_post'] ?? null;
            if (is_array($sqlPost) && $sqlPost !== [] && $setupBlocked === null && $dbName !== '') {
                try {
                    $this->runSqlPost($dbName, $dbUser, $dbPass, (string) ($conf['prefix'] ?? ($manifest['database']['prefix'] ?? '')), $sqlPost, [
                        '{user}' => $sUser,
                        '{email}' => $sEmail,
                        '{title}' => $sTitle,
                        '{date}' => date('Y-m-d H:i:s'),
                        // Bcrypt of the wizard password (Cacti rotates the
                        // seeded admin/admin to it after its CLI install).
                        '{pass_hash}' => (string) (password_hash($sPass, PASSWORD_BCRYPT) ?: ''),
                    ]);
                    $this->log($installationId, 'info', 'Applied post-setup database updates.');
                } catch (\Throwable $e) {
                    $this->log($installationId, 'warning', 'Post-setup database update failed (' . $e->getMessage() . ').');
                }
            }

            // 4d3. Generic post-setup cleanup: remove installer directories
            // (OpenCart install/, etc.) so a reinstall wizard is not exposed.
            // Skipped when setup was blocked (the browser installer is the
            // documented fallback until the PHP version is switched).
            if ($setupBlocked === null && (isset($manifest['setup']['cli']) || isset($manifest['setup']['sql']))) {
                foreach ((array) ($manifest['setup']['cleanup'] ?? []) as $rel) {
                $rel = trim((string) $rel, '/');
                if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
                    continue;
                }
                try {
                    app('provisioning')->execute('directory.remove', [
                        'account' => $username,
                        'path' => $destRel === '' ? $rel : $destRel . '/' . $rel,
                    ]);
                    $this->log($installationId, 'info', "Removed installer directory {$rel}");
                } catch (\Throwable $e) {
                    $this->log($installationId, 'warning', "Could not remove installer directory {$rel} (" . $e->getMessage() . ')');
                }
                }
            }

            // 4d2. Outside-tree data dirs (setup.datadirs, home-relative):
            // account-own + label for the confined PHP worker (fresh dirs
            // inherit user_home_t, which httpd_t cannot write).
            foreach ($this->setupDataDirs($manifest, $directory) as $rel) {
                try {
                    app('provisioning')->execute('installer.datadir.ensure', [
                        'account' => $username,
                        'path' => $rel,
                    ]);
                    $this->log($installationId, 'info', "Data directory ready: ~/{$rel}");
                } catch (\Throwable $e) {
                    $this->log($installationId, 'warning', "Data directory ~/{$rel} not secured (" . $e->getMessage() . ') — the app may fail writing outside its tree.');
                }
            }

            // 4d5. Recommended cron jobs (setup.cron): Mautic segments +
            // campaigns, Nextcloud background jobs. Created as ordinary
            // panel cron rows (count limits + feature gate respected) so
            // they show up, run, and are manageable like any other job.
            // Skipped when setup was blocked; never fatal.
            $cronSpecs = $manifest['setup']['cron'] ?? null;
            if (is_array($cronSpecs) && $cronSpecs !== [] && $setupBlocked === null) {
                $cronCreated = $this->setupAppCron(
                    $installationId,
                    $account,
                    $cronSpecs,
                    $this->setupPlaceholders(
                        $dbName, $dbUser, $dbPass, $conf, $manifest, $sTitle, $sUser, $sPass, $sEmail,
                        $siteUrl, $domain, $username, $directory, $account, $effPhpId, $opts
                    ) + ['{dest}' => $destAbs, '{phpbin}' => \Modules\Core\Services\PhpVersionsService::cliBinary($effPhpId)],
                    $dbPass,
                    $sPass
                );
                if ($cronCreated !== []) {
                    // Persisted (non-secret by construction — see the guard
                    // in setupAppCron) so uninstall can remove exactly these.
                    $opts['cron_commands'] = $cronCreated;
                }
            }

            // 4d6. WAF exclusions (setup.waf): apps whose normal traffic a
            // CRS rule breaks (Koel's REST API vs 911100 method
            // enforcement). Recorded like cron_commands so uninstall removes
            // exactly what the install added — never a hand-made exclusion.
            // Applies even when setup was blocked: the app still needs them
            // once its browser wizard finishes. Never fatal.
            $wafSpecs = $manifest['setup']['waf'] ?? null;
            if (is_array($wafSpecs) && ($wafSpecs['exclude'] ?? null) !== null) {
                $wafAdded = $this->setupAppWaf(
                    $installationId,
                    $account,
                    $domain,
                    (array) $wafSpecs['exclude'],
                    (string) ($application['name'] ?? 'application')
                );
                if ($wafAdded !== []) {
                    $opts['waf_exclusions'] = $wafAdded;
                }
            }

            // 4e. Declarative SQL setup (setup.sql): import the shipped
            // schema with a prefix swap, insert the admin row, remove
            // installer dirs. The panel-managed DB above is reused.
            $sqlSetup = $manifest['setup']['sql'] ?? null;
            if (is_array($sqlSetup) && (isset($sqlSetup['schema']) || isset($sqlSetup['schemas']))) {
                if ($setupBlocked !== null) {
                    $this->log($installationId, 'warning', 'Automatic setup skipped (' . $setupBlocked . ')');
                } elseif ($haveSetupCreds && $dbName !== '') {
                    $this->runSqlSetup($installationId, $username, $destRel, $sqlSetup, [
                        'db_host' => (string) env('DB_HOST', '127.0.0.1'),
                        'db_name' => $dbName,
                        'db_user' => $dbUser,
                        'db_pass' => $dbPass,
                        'prefix' => (string) ($conf['prefix'] ?? ($manifest['database']['prefix'] ?? '')),
                        'title' => $sTitle,
                        'user' => $sUser,
                        'pass' => $sPass,
                        'email' => $sEmail,
                        'url' => $siteUrl,
                        'domain' => $domain,
                    ], $siteUrl, (string) ($application['name'] ?? 'application'));
                } else {
                    $this->log($installationId, 'info', 'No setup credentials queued — open the site URL to finish setup in the browser.');
                }
            }
        }

        // 5. Register success.
        $adminPath = (string) ($manifest['admin']['path'] ?? '');
        $domain = (string) $opts['domain'];
        $siteDir = $directory !== '' ? '/' . $directory : '';
        $siteUrl = ($opts['scheme'] ?? 'https') . '://' . $domain . $siteDir;

        app('db')->update('ai_installations', [
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_password' => hash('sha256', $dbPass),
            'admin_user' => (string) $opts['admin_user'],
            'site_url' => $siteUrl,
            'admin_url' => $adminPath !== '' ? $siteUrl . '/' . $adminPath : '',   // note: wp admin is /wp-admin/ (absolute)
            'config' => json_encode($this->safeConfig($opts)),
            'status' => 'completed',
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$installationId]);

        // Subdirectory installs need their own front-controller location in
        // the vhost (the server-level fallback points at the domain root).
        // Domain-root installs need one too when the manifest renders
        // server-level blocks (framework `webroot` docroot rerouting,
        // `page_param` routers, `webroot_routes`/`webroot_static`,
        // `deny_paths`) — otherwise the fresh blocks never reach the vhost
        // until someone re-saves the website (bitten live: a root
        // WonderCMS served with no rewrite and no denies).
        // Best-effort: a vhost failure must not fail an otherwise complete
        // install (the admin can re-save the website to retry).
        $needsRootRender = $directory === '' && (int) ($setupSite['id'] ?? 0) > 0
            && (!empty($manifest['webroot']) || !empty($manifest['page_param'])
                || !empty($manifest['deny_paths']) || !empty($manifest['webroot_routes'])
                || !empty($manifest['webroot_static']));
        if (($directory !== '' || $needsRootRender) && (int) ($setupSite['id'] ?? 0) > 0) {
            try {
                app(\Modules\Websites\Services\PrivacyService::class)->regenerate((int) $setupSite['id']);
                $this->log($installationId, 'info', $directory !== ''
                    ? 'Website configuration refreshed for the subdirectory.'
                    : 'Website configuration refreshed for the domain root.');
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', 'Could not refresh the website config (' . $e->getMessage() . ') — re-save the website to retry.');
            }
        }

        $this->log($installationId, 'info', 'Installation complete');
    }
}
