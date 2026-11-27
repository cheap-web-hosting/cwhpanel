<?php

declare(strict_types=1);

namespace Modules\AppInstaller\Services\Installer;

/**
 * @internal Extracted from InstallerService (concern group).
 */
trait CreateExecuteTrait
{


    /**
     * Create a pending installation row and return its id. The actual work is
     * done asynchronously by a worker via execute() (or synchronously here
     * when the caller runs it inline).
     */
    public function create(int $accountId, array $application, array $opts): int
    {
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }
        // Service-layer feature gate (route middleware cannot see worker
        // jobs or direct service calls): a package without the App Installer
        // cannot queue installs at all.
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'appinstaller');

        $docroot = rtrim((string) $opts['docroot'], '/');
        $directory = trim((string) $opts['directory'], '/');

        $manifest = $this->catalog->manifest($application);
        // Root-only apps (upstream bans subdirectories — Invoice Ninja's
        // React bundle uses domain-absolute asset URLs): refuse a
        // subdirectory before anything is provisioned, with guidance.
        if (!empty($manifest['root_only']) && $directory !== '') {
            throw new \RuntimeException(
                (string) ($application['name'] ?? 'This application')
                . ' must be installed at a website root (its frontend does not support subdirectories)'
                . ' — create a subdomain or website with an empty document root and leave the directory empty.'
            );
        }
        // Root installs never auto-pin (pins are per-directory), so a root
        // install whose manifest demands PHP settings (Chamilo's heavy step
        // needs time + memory) would silently run on stock defaults. Refuse
        // up front with exact guidance instead — the settings are one save
        // away on the website's PHP Settings page.
        if ($directory === '' && isset($manifest['php_settings']) && is_array($manifest['php_settings']) && $manifest['php_settings'] !== []) {
            $this->assertRootPhpSettings($manifest, (array) $account, $docroot, (string) ($application['name'] ?? 'This application'));
        }
        // Package PHP ceilings are hard caps: a manifest that needs more
        // memory/time than the package allows would be clamped and break
        // mid-install. Refuse before any provisioning with exact guidance.
        $this->assertPhpCapFit($manifest, (array) $account, (string) ($application['name'] ?? 'This application'));
        // Service-layer count gate: app-type installs mint supervised
        // runtime rows outside the Applications controllers.
        $limitType = match ($manifest['type'] ?? '') {
            'python-pip' => 'python',
            'node-npm', 'node-archive' => 'node',
            default => null,
        };
        if ($limitType !== null) {
            \Modules\Packages\Services\LimitService::require($accountId, $limitType);
        }
        $extra = [];
        $path = $docroot . ($directory !== '' ? '/' . $directory : '');
        if (self::isAppType($manifest)) {
            // App installs live in ~/apps/<slug> (the supervised work dir),
            // not the docroot; the website mount comes from website_id.
            $home = $account['home_dir'] ?? '/home/' . $account['username'];
            $basePath = '/' . trim((string) ($opts['base_path'] ?? $directory), '/');
            $basePath = $basePath === '/' ? '/' : rtrim($basePath, '/');
            $path = $home . '/apps/' . $application['slug'];
            $extra = [
                'website_id' => (int) ($opts['website_id'] ?? 0),
                'base_path' => $basePath,
            ];
        } elseif ((string) (($manifest['download'] ?? [])['format'] ?? '') === 'file') {
            // Single-file installs address the file itself (update/uninstall
            // target it directly, not its directory).
            $fileName = (string) ($manifest['file']['name'] ?? '');
            if ($fileName === '') {
                throw new \RuntimeException('Single-file manifest needs file.name.');
            }
            $path = $path . '/' . $fileName;
        }

        return (int) app('db')->insert('ai_installations', [
            'account_id' => $accountId,
            'application_slug' => $application['slug'],
            'app_name' => $application['name'],
            'version' => $application['version'],
            'domain' => (string) $opts['domain'],
            'path' => $path,
            'directory' => $directory,
            'db_name' => '',
            'db_user' => '',
            'db_password' => '',
            'admin_user' => (string) ($opts['admin_user'] ?? ''),
            'admin_url' => '',
            'site_url' => '',
            'config' => json_encode($this->safeConfig($opts + ['docroot' => $docroot, 'scheme' => $opts['scheme'] ?? 'https'] + $extra)),
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }


    /**
     * Run the actual installation for an existing (pending) installation row.
     * Idempotent + resumable: re-derives everything from the stored row + app.
     *
     * $setup carries the unattended-setup credentials (title / admin_user /
     * admin_password / admin_email) from the install job payload — transient
     * by design, never persisted (JobRunner scrubs the password once the job
     * settles). Installs queued before this shipped carry none and keep the
     * old files+DB behavior (manual wizard).
     */
    public function execute(int $installationId, array $setup = []): void
    {
        $inst = app('db')->selectOne('SELECT * FROM `ai_installations` WHERE `id` = ?', [$installationId]);
        if ($inst === null) {
            throw new \RuntimeException('Installation not found.');
        }
        if ($inst['status'] === 'completed') {
            return; // already done
        }
        $application = $this->catalog->find($inst['application_slug']);
        if ($application === null) {
            throw new \RuntimeException('Application no longer exists in the catalog.');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $inst['account_id']]);
        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }
        // Queued before a package change? Re-check at execution time: a job
        // created while allowed must not install after the feature is gone.
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'appinstaller');

        $opts = array_merge(json_decode((string) ($inst['config'] ?? '{}'), true) ?: [], [
            'domain' => $inst['domain'],
            'directory' => $inst['directory'],
            'admin_user' => $inst['admin_user'],
            'setup' => $setup,
        ]);

        app('db')->update('ai_installations', ['status' => 'running', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$installationId]);

        try {
            $manifest = $this->catalog->manifest($application);
            if (self::isAppType($manifest)) {
                $this->runAppInstall($installationId, $account, $application, $manifest, $opts);
            } else {
                $this->runInstall($installationId, $account, $application, $opts);
            }
        } catch (\Throwable $e) {
            app('db')->update('ai_installations', ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$installationId]);
            $this->log($installationId, 'error', $e->getMessage());
            throw $e;
        }
    }


    /**
     * Manifest types that provision a supervised Applications-module process
     * behind a reverse-proxy location (Node.js/Python) instead of a PHP
     * docroot install.
     */
    public static function isAppType(array $manifest): bool
    {
        return in_array($manifest['type'] ?? '', ['python-pip', 'node-npm', 'node-archive', 'binary'], true);
    }
}
