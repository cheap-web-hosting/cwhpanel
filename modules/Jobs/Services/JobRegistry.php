<?php
declare(strict_types=1);
namespace Modules\Jobs\Services;

/**
 * Maps job types to handlers.
 *
 * Handlers receive the decoded payload array and return a scalar/array result.
 * A handler throwing an exception marks the job failed (and may be retried).
 */
class JobRegistry
{
    /**
     * Job type -> module that must be enabled for the handler to exist.
     *
     * Handlers below reference other modules' services directly. With the
     * module toggle those classes/files may be absent (disabled module or a
     * free-edition tree), so every gated type is checked up front and fails
     * with an actionable message instead of a "class not found" fatal.
     */
    private const REQUIRES = [
        'provision.account' => 'accounts',
        'backup.create' => 'backups',
        'backup.scheduled' => 'backups',
        'appinstaller.install' => 'appinstaller',
        'appinstaller.update' => 'appinstaller',
        'appinstaller.uninstall' => 'appinstaller',
        'app.runtime.install' => 'applications',
        'python.package.install' => 'applications',
        'python.package.uninstall' => 'applications',
        'app.deploy' => 'applications',
        'app.update' => 'applications',
        'ssl.renew' => 'ssl',
        'ssl.autoissue' => 'ssl',
    ];

    public static function handle(string $type, array $payload): mixed
    {
        // Module-owned job types register their handler in Module::jobs().
        // This keeps the shared registry free of references to modules that
        // may be disabled or removed entirely (free edition).
        try {
            $handler = app()->moduleManager()->jobHandler($type);
        } catch (\Throwable) {
            $handler = null;
        }

        if ($handler !== null) {
            return $handler($payload);
        }

        $required = self::REQUIRES[$type] ?? null;
        if ($required !== null && !self::moduleAvailable($required)) {
            throw new \RuntimeException(
                "job type {$type} is unavailable: module '{$required}' is disabled or not installed"
            );
        }

        return match ($type) {
            'provision.account' => self::provisionAccount($payload),
            'backup.create' => self::backupCreate($payload),
            'appinstaller.install' => self::appInstall($payload),
            'appinstaller.update' => self::appUpdate($payload),
            'appinstaller.uninstall' => self::appUninstall($payload),
            'php.install' => self::phpInstall($payload),
            'php.uninstall' => self::phpUninstall($payload),
            'php.module.install' => self::phpModuleInstall($payload),
            'app.runtime.install' => self::appRuntimeInstall($payload),
            'python.package.install' => self::pythonPackageInstall($payload),
            'python.package.uninstall' => self::pythonPackageUninstall($payload),
            'ssl.renew' => self::sslRenew($payload),
            'ssl.autoissue' => self::sslAutoIssue($payload),
            'backup.scheduled' => self::backupScheduled($payload),
            'app.deploy' => self::appDeploy($payload),
            'app.update' => self::runtimeAppUpdate($payload),
            'system.updates.check' => self::systemUpdatesCheck($payload),
            'system.updates.apply' => self::systemUpdatesApply($payload),
            'system.hostname.change' => self::systemHostnameChange($payload),
            'nginx.reload.coalesced' => self::nginxReloadCoalesced($payload),
            default => throw new \RuntimeException("no handler for job type: {$type}"),
        };
    }

    private static function appInstall(array $payload): string
    {
        $id = (int) ($payload['installation_id'] ?? 0);
        // Transient unattended-setup credentials (never persisted): the
        // installer consumes them for the automatic setup, then JobRunner
        // scrubs the password once the job settles.
        $setup = [];
        foreach (['title', 'admin_user', 'admin_password', 'admin_email'] as $k) {
            if (isset($payload[$k]) && is_string($payload[$k]) && $payload[$k] !== '') {
                $setup[$k] = $payload[$k];
            }
        }
        app(\Modules\AppInstaller\Services\InstallerService::class)->execute($id, $setup);
        return "installed application (installation {$id})";
    }

    private static function appUpdate(array $payload): string
    {
        $id = (int) ($payload['installation_id'] ?? 0);
        app(\Modules\AppInstaller\Services\InstallerService::class)->update($id);
        return "updated application (installation {$id})";
    }

    private static function appUninstall(array $payload): string
    {
        $id = (int) ($payload['installation_id'] ?? 0);
        app(\Modules\AppInstaller\Services\InstallerService::class)->uninstall(
            $id,
            (bool) ($payload['remove_files'] ?? true),
            (bool) ($payload['remove_db'] ?? true)
        );
        return "uninstalled application (installation {$id})";
    }

    private static function phpInstall(array $payload): string
    {
        $version = (string) ($payload['version'] ?? '');
        app(\Modules\Core\Services\PhpVersionsService::class)->install($version);
        return "installed PHP {$version}";
    }

    private static function phpUninstall(array $payload): string
    {
        $version = (string) ($payload['version'] ?? '');
        app(\Modules\Core\Services\PhpVersionsService::class)->uninstall($version);
        return "uninstalled PHP {$version}";
    }

    private static function phpModuleInstall(array $payload): string
    {
        $version = (string) ($payload['version'] ?? '');
        $module = (string) ($payload['module'] ?? '');
        $svc = app(\Modules\Core\Services\PhpVersionsService::class);
        // Provide the RPM system-wide (idempotent). Pooled PHP shares one
        // daemon per version, so the extension is available to every pool on
        // that version once installed.
        $svc->installModule($version, $module);
        return "installed PHP module {$module} for PHP {$version}";
    }

    private static function appRuntimeInstall(array $payload): string
    {
        $runtime = (string) ($payload['runtime'] ?? '');
        $version = (string) ($payload['version'] ?? '');
        if (\Modules\Applications\Services\AppService::normalizeVersion($runtime, $version) !== $version
            || $version === \Modules\Applications\Services\AppService::defaultVersion($runtime)) {
            throw new \RuntimeException('Nothing to install (unknown or default runtime).');
        }
        $svc = app(\Modules\Applications\Services\AppService::class);
        if ($runtime === 'node') {
            [$exact, $sha] = \Modules\Applications\Services\AppService::resolveNode($version);
            $svc->installRuntime($runtime, $version, $exact, $sha);
            return "installed node {$exact}";
        }
        $svc->installRuntime($runtime, $version);
        return "installed {$runtime} {$version}";
    }

    private static function sslRenew(array $payload): string
    {
        $threshold = (int) ($payload['days_threshold'] ?? 30);
        $result = app(\Modules\Ssl\Services\SslService::class)->renewDue($threshold);
        return "ssl renew sweep: {$result['renewed']} renewed, {$result['failed']} failed, " . ($result['skipped'] ?? 0) . " skipped (rate-limit backoff)";
    }

    /**
     * Automatic LE issuance for a freshly-added domain (queued by
     * DomainService::provision). Skips (no throw — DNS may not have
     * propagated yet) when the domain does not resolve here; throws on
     * hard issuance failure so the worker retries, then marks failed.
     */
    private static function sslAutoIssue(array $payload): string
    {
        return app(\Modules\Ssl\Services\SslService::class)->runAutoIssue(
            (int) ($payload['account_id'] ?? 0),
            (int) ($payload['domain_id'] ?? 0),
            (string) ($payload['domain'] ?? '')
        );
    }

    private static function backupScheduled(array $payload): string
    {
        $id = (int) ($payload['schedule_id'] ?? 0);
        return app(\Modules\Backups\Services\BackupService::class)->runScheduled($id);
    }

    /**
     * Push-to-deploy: git-fetch the app's remote into its work dir. Records
     * last-deploy bookkeeping on the row either way; failures throw (the
     * runner retries, then marks the job failed — never silently passing).
     * Runs under the package feature gate (a removed applications feature
     * stops deploys instead of bypassing it).
     */
    private static function appDeploy(array $payload): string
    {
        $id = (int) ($payload['application_id'] ?? 0);
        $app = app('db')->selectOne('SELECT * FROM `applications` WHERE `id` = ?', [$id]);
        if ($app === null) {
            throw new \RuntimeException("unknown application ({$id})");
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $app['account_id']]);
        if ($account === null) {
            throw new \RuntimeException("application {$id} has no account");
        }
        try {
            $ok = app(\Modules\Applications\Services\AppService::class)->deploy($account, $app);
        } catch (\Throwable $e) {
            self::recordDeploy($id, 'failed');
            throw $e;
        }
        if (!$ok) {
            self::recordDeploy($id, 'failed');
            throw new \RuntimeException('deploy returned failure (no git remote configured?)');
        }
        self::recordDeploy($id, 'success');
        return "deployed application {$app['name']} (id {$id})";
    }

    private static function recordDeploy(int $id, string $status): void
    {
        try {
            app('db')->update('applications', [
                'last_deploy_at' => date('Y-m-d H:i:s'),
                'last_deploy_status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$id]);
        } catch (\Throwable $ignored) {
            // Bookkeeping must never break the deploy itself.
        }
    }

    /**
     * Runtime app update: git pull + dependency refresh + restart when
     * running. Queued from the UI (dependency installs can take minutes);
     * the summary lands in the job result. (appUpdate() above belongs to
     * the catalog installer — hence the distinct name.)
     */
    private static function runtimeAppUpdate(array $payload): string
    {
        $id = (int) ($payload['application_id'] ?? 0);
        $app = app('db')->selectOne('SELECT * FROM `applications` WHERE `id` = ?', [$id]);
        if ($app === null) {
            throw new \RuntimeException("unknown application ({$id})");
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $app['account_id']]);
        if ($account === null) {
            throw new \RuntimeException("application {$id} has no account");
        }
        $summary = app(\Modules\Applications\Services\AppService::class)->updateApp($account, $app);
        return "updated application {$app['name']} (id {$id}): {$summary}";
    }

    private static function systemUpdatesCheck(array $payload): string
    {
        $s = app(\Modules\Core\Services\UpdatesService::class)->checkNow();
        return "update check: {$s['total']} pending ({$s['security']} security)";
    }

    /** Manual "Install now" (admin → Server → Updates) — dnf per the chosen scope. */
    private static function systemUpdatesApply(array $payload): string
    {
        $mode = (string) ($payload['mode'] ?? 'security');
        return app(\Modules\Core\Services\UpdatesService::class)->applyNow($mode);
    }

    private static function systemHostnameChange(array $payload): string
    {
        $fqdn = (string) ($payload['fqdn'] ?? '');
        $res = app(\Modules\Core\Services\HostnameService::class)->change($fqdn);
        $res['at'] = date('Y-m-d H:i:s');
        app('db')->statement(
            'INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
            ['system.hostname_status', json_encode($res), date('Y-m-d H:i:s')]
        );
        $failed = array_filter($res['steps'], static fn ($s) => !$s['ok']);
        return 'hostname ' . $res['fqdn'] . ': ' . (count($res['steps']) - count($failed)) . '/' . count($res['steps']) . ' steps ok'
            . ($failed ? ' (failed: ' . implode(', ', array_column($failed, 'step')) . ')' : '');
    }

    private static function pythonPackageInstall(array $payload): string
    {
        [$account, $app] = self::loadApp((int) ($payload['application_id'] ?? 0));
        $package = \Modules\Applications\Services\AppService::normalizePackage((string) ($payload['package'] ?? ''));
        app(\Modules\Applications\Services\AppService::class)->installPackage($account, $app, $package);
        return "installed python package {$package} on {$app['name']}";
    }

    private static function pythonPackageUninstall(array $payload): string
    {
        [$account, $app] = self::loadApp((int) ($payload['application_id'] ?? 0));
        $package = \Modules\Applications\Services\AppService::normalizePackage((string) ($payload['package'] ?? ''));
        app(\Modules\Applications\Services\AppService::class)->uninstallPackage($account, $app, $package);
        return "uninstalled python package {$package} from {$app['name']}";
    }

    /** @return array{0:array,1:array} [account, python app] */
    private static function loadApp(int $id): array
    {
        $app = app('db')->selectOne('SELECT * FROM `applications` WHERE `id` = ?', [$id]);
        if ($app === null || ($app['runtime'] ?? '') !== 'python') {
            throw new \RuntimeException('python application not found');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $app['account_id']]);
        if ($account === null) {
            throw new \RuntimeException('account not found');
        }
        return [$account, $app];
    }

    private static function provisionAccount(array $payload): string
    {
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [($payload['account_id'] ?? 0)]);
        if ($account === null) {
            throw new \RuntimeException('account not found');
        }

        $package = app('db')->selectOne('SELECT * FROM `packages` WHERE `id` = ?', [$account['package_id'] ?? 0]);
        $results = app(\Modules\Accounts\Services\AccountProvisioner::class)->provision($account, $package ?: []);

        $failed = \Modules\Accounts\Services\AccountProvisioner::failures($results);

        return "provisioned {$account['username']} (" . count($results) . " ops, {$failed} failed)";
    }

    private static function backupCreate(array $payload): string
    {
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [($payload['account_id'] ?? 0)]);
        if ($account === null) {
            throw new \RuntimeException('account not found');
        }

        $name = (string) ($payload['name'] ?? ($account['username'] . '-' . date('Ymd-His')));
        $ok = app(\Modules\Backups\Services\BackupService::class)->create((int) $account['id'], $name);

        return $ok ? "backup {$name} created" : "backup {$name} failed";
    }

    /**
     * A module is available when it is enabled AND its code is present. The
     * files check keeps a free-edition tree (module directory removed) from
     * fatalling on a class reference that no longer resolves.
     */
    /**
     * Coalesced global nginx reload (security audit #23): customer WAF
     * changes write their vhosts with defer_reload and queue this single job,
     * so N tenants cause one reload instead of N.
     *
     * @return array<string,mixed>
     */
    private static function nginxReloadCoalesced(array $payload): array
    {
        app('provisioning')->execute('nginx.reload', []);

        return ['reloaded' => true, 'at' => date('Y-m-d H:i:s')];
    }

    private static function moduleAvailable(string $module): bool
    {
        try {
            if (!app()->moduleManager()->isEnabled($module)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $className = 'Modules\\' . ucfirst($module) . '\\Module';

        return class_exists($className);
    }
}