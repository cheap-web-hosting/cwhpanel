<?php

declare(strict_types=1);

namespace Modules\Packages\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Core\Services\PhpVersionsService;

class PackageController
{
    private const RESOURCE_FIELDS = [
        'disk_quota_mb', 'bandwidth_mb', 'max_domains', 'max_subdomains', 'max_databases',
        'max_email_accounts', 'max_ftp_accounts', 'max_cron_jobs', 'max_nodejs_apps',
        'max_python_apps', 'max_cpu_percent', 'max_memory_mb', 'max_processes',
        'max_io_mbs', 'disk_inodes', 'max_iops',
        'php_max_children', 'php_memory_limit_mb', 'php_max_execution_time',
        'max_node_memory_mb', 'max_python_memory_mb',
        'database_quota_mb', 'max_connections', 'backup_quota_mb',
        'nginx_workers', 'nginx_max_body_mb', 'nginx_req_per_sec', 'nginx_req_burst',
        'mail_max_out_per_hour', 'mail_max_out_per_day', 'mailbox_max_out_per_hour',
        'mailbox_quota_max_mb',
        'mysql_max_user_connections', 'mysql_max_queries_per_hour',
        'mysql_max_updates_per_hour', 'mysql_max_statement_sec',
    ];

    /** Count limits: NULL = unlimited, 0 = none allowed (see LimitService). */
    private const COUNT_FIELDS = [
        'max_domains', 'max_subdomains', 'max_databases', 'max_email_accounts',
        'max_ftp_accounts', 'max_cron_jobs', 'max_nodejs_apps', 'max_python_apps',
    ];

    /**
     * Upper bounds for the new numeric limits. 0 stays valid everywhere
     * (unlimited); these only reject typos/absurd values before they reach the
     * agent (which validates again, e.g. max_children must be <= 64).
     */
    private const LIMIT_RANGES = [
        'disk_inodes' => 1_000_000_000,
        'max_iops' => 1_000_000,
        'php_max_children' => 64,
        'php_memory_limit_mb' => 1_048_576,
        'php_max_execution_time' => 86_400,
        'max_node_memory_mb' => 1_048_576,
        'max_python_memory_mb' => 1_048_576,
        'database_quota_mb' => 10_000_000,
        'max_connections' => 100_000,
        'backup_quota_mb' => 10_000_000,
    ];

    public function index(): Response
    {
        $packages = app('db')->select(
            'SELECT p.*, (SELECT COUNT(*) FROM `accounts` a WHERE a.`package_id` = p.`id`) AS `account_count`
             FROM `packages` p ORDER BY p.`is_system` DESC, p.`name`'
        );

        $content = view('admin/packages/index', [
            'title' => 'Hosting Packages',
            'packages' => $packages,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        return $this->form(null, []);
    }

    public function store(Request $request): Response
    {
        try {
            $data = $request->validate($this->rules());
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/packages/create');
        }

        $data = $this->applyUnlimited($data, (array) $request->input('unlimited', []));

        if (($errors = $this->rangeErrors($data)) !== []) {
            return $this->backWithErrors($request, new ValidationException($errors), '/admin/packages/create');
        }

        $id = $this->save(null, $data);

        app(\Modules\Packages\Services\FeatureService::class)
            ->setForPackage($id, (array) $request->input('features', []));

        audit('package.created', 'package', $id, ['name' => $data['name']]);
        app('session')->flash('success', 'Package created.');

        return Response::redirect('/admin/packages');
    }

    public function show(int $id): Response
    {
        $package = $this->find($id);

        if ($package === null) {
            return Response::redirect('/admin/packages');
        }

        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `package_id` = ? ORDER BY `username`', [$id]);

        $features = app(\Modules\Packages\Services\FeatureService::class)->forPackage($id);

        $content = view('admin/packages/show', [
            'title' => 'Package: ' . $package['name'],
            'package' => $package,
            'accounts' => $accounts,
            'features' => $features,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function edit(int $id): Response
    {
        $package = $this->find($id);

        if ($package === null) {
            return Response::redirect('/admin/packages');
        }

        return $this->form($package, []);
    }

    public function update(int $id, Request $request): Response
    {
        $package = $this->find($id);

        if ($package === null) {
            return Response::redirect('/admin/packages');
        }

        try {
            $data = $request->validate($this->rules());
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/packages/' . $id . '/edit');
        }

        $data = $this->applyUnlimited($data, (array) $request->input('unlimited', []));

        if (($errors = $this->rangeErrors($data)) !== []) {
            return $this->backWithErrors($request, new ValidationException($errors), '/admin/packages/' . $id . '/edit');
        }

        $oldWorkers = (int) ($package['nginx_workers'] ?? 2);
        $oldBody = (int) ($package['nginx_max_body_mb'] ?? 32);
        $oldRps = (int) ($package['nginx_req_per_sec'] ?? 20);
        $oldBurst = (int) ($package['nginx_req_burst'] ?? 40);

        $this->save($id, $data);

        $featureSvc = app(\Modules\Packages\Services\FeatureService::class);
        $oldFeatures = $featureSvc->forPackage($id);
        $featureSvc->setForPackage($id, (array) $request->input('features', []));
        $newFeatures = $featureSvc->forPackage($id);

        audit('package.updated', 'package', $id, ['name' => $data['name']]);

        // Auto-apply nginx limit changes to isolated accounts on this package.
        $newWorkers = (int) ($data['nginx_workers'] ?? $oldWorkers);
        $newBody = (int) ($data['nginx_max_body_mb'] ?? $oldBody);
        $note = '';
        if ($newWorkers !== $oldWorkers || $newBody !== $oldBody) {
            $note = $this->applyNginxLimits($id);
        }

        // Auto-apply bot-shield default changes to inheriting sites.
        $newRps = (int) ($data['nginx_req_per_sec'] ?? $oldRps);
        $newBurst = (int) ($data['nginx_req_burst'] ?? $oldBurst);
        if ($newRps !== $oldRps || $newBurst !== $oldBurst) {
            $note .= $this->applyRateLimits($id);
        }

        // Auto-apply cgroup v2 slice caps (CPU/memory/processes/IO) to active
        // accounts on this package — limits are enforced live, not just set at
        // creation time. The agent's set-property applies them immediately.
        $sliceFields = ['max_cpu_percent', 'max_memory_mb', 'max_processes', 'max_io_mbs', 'max_iops'];
        $sliceChanged = false;
        foreach ($sliceFields as $f) {
            if ((int) ($data[$f] ?? 0) !== (int) ($package[$f] ?? 0)) {
                $sliceChanged = true;
                break;
            }
        }
        if ($sliceChanged) {
            $note .= $this->applyResourceLimits($id);
        }

        // Inode ceilings ride the same XFS quota call as the byte cap.
        if ((int) ($data['disk_inodes'] ?? 0) !== (int) ($package['disk_inodes'] ?? 0)) {
            $note .= $this->applyDiskQuota($id);
        }

        // PHP ceilings (workers / memory / exec time) must reach masters that
        // are already provisioned, otherwise only new accounts inherit them.
        $phpFields = ['php_max_children', 'php_memory_limit_mb', 'php_max_execution_time'];
        $phpChanged = false;
        foreach ($phpFields as $f) {
            if ((int) ($data[$f] ?? 0) !== (int) ($package[$f] ?? 0)) {
                $phpChanged = true;
                break;
            }
        }
        if ($phpChanged) {
            $note .= $this->applyPhpLimits($id);
        }

        // Supervised runtime memory caps apply at unit start; restart running
        // apps when their ceiling changed so the change is real, not cosmetic.
        $runtimeFields = ['max_node_memory_mb', 'max_python_memory_mb'];
        $runtimeChanged = false;
        foreach ($runtimeFields as $f) {
            if ((int) ($data[$f] ?? 0) !== (int) ($package[$f] ?? 0)) {
                $runtimeChanged = true;
                break;
            }
        }
        if ($runtimeChanged) {
            $note .= $this->applyRuntimeMemory($id);
        }

        // A runtime count of 0 disables Node.js / Python outright: stop the
        // running units now (data kept; re-enable + manual start restores).
        foreach (['max_nodejs_apps' => 'node', 'max_python_apps' => 'python'] as $col => $runtime) {
            $oldZero = $package[$col] !== null && (int) $package[$col] === 0;
            // Blank means unlimited — only a literal 0 disables the runtime.
            $rawNew = $data[$col] ?? null;
            $newZero = $rawNew !== null && $rawNew !== '' && (int) $rawNew === 0;
            if ($newZero && !$oldZero) {
                $note .= $this->disableRuntime($id, $runtime);
            }
        }

        // Web concurrency ceilings live in the vhosts that inherit them.
        if ((int) ($data['max_connections'] ?? 0) !== (int) ($package['max_connections'] ?? 0)) {
            $note .= $this->applyConnLimits($id);
        }

        // Auto-apply MySQL Governor changes to every DB user on this package.
        $govFields = ['mysql_max_user_connections', 'mysql_max_queries_per_hour', 'mysql_max_updates_per_hour', 'mysql_max_statement_sec'];
        $govChanged = false;
        foreach ($govFields as $f) {
            if ((int) ($data[$f] ?? 0) !== (int) ($package[$f] ?? 0)) {
                $govChanged = true;
                break;
            }
        }
        if ($govChanged) {
            $note .= $this->applyMysqlLimits($id);
        }

        // Clamp mailbox quotas when the per-mailbox ceiling changed: any
        // mailbox above the new max drops to it (DB + Dovecot resync), so
        // the limit is enforced, not just gated on future creates. 0 =
        // unlimited, which never clamps.
        $oldQuotaMax = (int) ($package['mailbox_quota_max_mb'] ?? 1024);
        $newQuotaMax = (int) ($data['mailbox_quota_max_mb'] ?? $oldQuotaMax);
        if ($newQuotaMax !== $oldQuotaMax) {
            $note .= $this->applyMailboxQuota($id, $newQuotaMax);
        }

        // Suspend execution-bearing features removed from the package:
        // hiding the UI is not enough — running units, crontabs and shells
        // would otherwise keep working indefinitely.
        foreach (['applications', 'cron', 'security'] as $removed) {
            if (!empty($oldFeatures[$removed]) && empty($newFeatures[$removed])) {
                $note .= $this->suspendFeature($id, $removed);
            }
        }

        app('session')->flash('success', 'Package updated.' . $note);

        return Response::redirect('/admin/packages');
    }

    /**
     * Freeze a removed execution-bearing feature on every active account of
     * the package. Data is never deleted — units stop (restart manually
     * after re-enabling), cron rows pause (resync clears the crontab), SSH
     * shells lock (keys kept). Per-account failures are logged, never fatal
     * to the save.
     */
    private function suspendFeature(int $packageId, string $feature): string
    {
        $accounts = app('db')->select(
            'SELECT * FROM `accounts` WHERE `package_id` = ? AND `status` = ?',
            [$packageId, 'active']
        );
        if ($accounts === []) {
            return '';
        }

        $affected = 0;
        $failed = 0;
        foreach ($accounts as $account) {
            try {
                $n = match ($feature) {
                    'applications' => $this->suspendApps($account),
                    'cron' => $this->suspendCron((int) $account['id']),
                    'security' => $this->suspendSsh((string) $account['username']),
                    default => 0,
                };
                $affected += $n;
            } catch (\Throwable $e) {
                $failed++;
                app('logger')->warning("Feature suspend [{$feature}] failed for " . $account['username'] . ': ' . $e->getMessage());
            }
        }

        audit('package.feature_suspended', 'package', $packageId, ['feature' => $feature, 'accounts' => $affected, 'failed' => $failed]);
        $label = \Modules\Packages\Services\FeatureService::label($feature);
        return " {$label} suspended on {$affected} account(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /** Stop all runtime apps of one account. Returns 1 when anything stopped. */
    private function suspendApps(array $account): int
    {
        $apps = app('db')->select('SELECT * FROM `applications` WHERE `account_id` = ?', [(int) $account['id']]);
        if ($apps === []) {
            return 0;
        }
        $svc = app(\Modules\Applications\Services\AppService::class);
        foreach ($apps as $app) {
            try {
                $svc->stop($account, $app);
            } catch (\Throwable $e) {
                app('logger')->warning('App stop failed for ' . $account['username'] . '/' . $app['name'] . ': ' . $e->getMessage());
            }
        }
        app('db')->update(
            'applications',
            ['status' => 'stopped', 'updated_at' => date('Y-m-d H:i:s')],
            '`account_id` = ?',
            [(int) $account['id']]
        );
        return 1;
    }

    /** Pause all cron rows of one account and rebuild its system crontab. */
    private function suspendCron(int $accountId): int
    {
        $n = 0;
        foreach (app('db')->select('SELECT `id` FROM `cron_jobs` WHERE `account_id` = ? AND `status` = ?', [$accountId, 'enabled']) as $row) {
            app('db')->update('cron_jobs', ['status' => 'disabled', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $row['id']]);
            $n++;
        }
        // Resync even when nothing was enabled: guarantees the system
        // crontab cannot outlive the panel rows.
        app(\Modules\Cron\Services\CronService::class)->sync($accountId);
        return $n > 0 ? 1 : 0;
    }

    /** Lock one account's shell (jail torn down, keys kept for re-enable). */
    private function suspendSsh(string $username): int
    {
        $res = app(\Modules\Security\Services\SshService::class)->setMode($username, 'off');
        if (empty($res['ok'])) {
            throw new \RuntimeException((string) ($res['error'] ?? 'SSH suspend failed.'));
        }
        return 1;
    }

    /**
     * Rewrite + restart the dedicated workers of isolated active accounts on
     * a package. Per-account failures are logged, never fatal to the save.
     */
    private function applyNginxLimits(int $packageId): string
    {
        $accounts = app('db')->select(
            'SELECT * FROM `accounts`
             WHERE `package_id` = ? AND `status` = ? AND `nginx_worker_enabled` = 1',
            [$packageId, 'active']
        );

        if ($accounts === []) {
            return '';
        }

        $ok = 0;
        $failed = 0;
        $isolation = app(\Modules\Core\Services\NginxIsolationService::class);
        foreach ($accounts as $account) {
            try {
                $isolation->enable($account);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                app('logger')->warning('Worker re-apply failed for ' . $account['username'] . ': ' . $e->getMessage());
            }
        }

        audit('package.nginx_limits_applied', 'package', $packageId, ['applied' => $ok, 'failed' => $failed]);
        return " Nginx limits applied to {$ok} worker(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /**
     * Regenerate rate-limited sites that inherit this package's bot-shield
     * defaults (NULL overrides), then re-sync the shared zone file.
     * Per-site failures are logged, never fatal to the save.
     */
    private function applyRateLimits(int $packageId): string
    {
        $sites = app('db')->select(
            'SELECT w.`id` FROM `websites` w
              JOIN `accounts` a ON a.`id` = w.`account_id`
              WHERE a.`package_id` = ? AND w.`status` = ? AND w.`rate_limit_enabled` = 1
                AND (w.`rate_limit_rps` IS NULL OR w.`rate_limit_burst` IS NULL)',
            [$packageId, 'active']
        );

        if ($sites === []) {
            try {
                app(\Modules\Websites\Services\RateLimitService::class)->syncZones();
            } catch (\Throwable $e) {
            }
            return '';
        }

        $ok = 0;
        $failed = 0;
        $regen = app(\Modules\Websites\Services\PrivacyService::class);
        $package = $this->find($packageId);
        $newRps = (int) ($package['nginx_req_per_sec'] ?? 0);
        // Enable-path ordering: a vhost must not reference a zone that does
        // not exist yet, so sync zones first when the new cap throttles.
        // Disable-path ordering: push the clean vhost first, then drop zones.
        if ($newRps > 0) {
            try {
                app(\Modules\Websites\Services\RateLimitService::class)->syncZones();
            } catch (\Throwable $e) {
                app('logger')->warning('Rate-limit zone pre-sync failed: ' . $e->getMessage());
            }
        }
        foreach ($sites as $site) {
            try {
                $regen->regenerate((int) $site['id']);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                app('logger')->warning('Rate-limit re-apply failed for website ' . $site['id'] . ': ' . $e->getMessage());
            }
        }

        try {
            app(\Modules\Websites\Services\RateLimitService::class)->syncZones();
        } catch (\Throwable $e) {
            app('logger')->warning('Rate-limit zone re-sync failed: ' . $e->getMessage());
        }

        audit('package.ratelimit_applied', 'package', $packageId, ['applied' => $ok, 'failed' => $failed]);
        return " Bot-shield defaults applied to {$ok} site(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /**
     * Re-apply MySQL Governor caps to every DB user of every active account
     * on a package. Per-account failures are logged, never fatal to the save.
     */
    private function applyMysqlLimits(int $packageId): string
    {
        $accounts = app('db')->select(
            'SELECT `id`, `username` FROM `accounts` WHERE `package_id` = ? AND `status` = ?',
            [$packageId, 'active']
        );
        if ($accounts === []) {
            return '';
        }
        $gov = app(\Modules\Databases\Services\MysqlGovernorService::class);
        $ok = 0;
        $failed = 0;
        foreach ($accounts as $account) {
            try {
                $gov->applyAccountLimits((int) $account['id']);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                app('logger')->warning('MySQL governor re-apply failed for ' . $account['username'] . ': ' . $e->getMessage());
            }
        }
        audit('package.mysql_limits_applied', 'package', $packageId, ['applied' => $ok, 'failed' => $failed]);
        return " MySQL Governor caps applied to {$ok} account(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /**
     * Re-apply cgroup v2 slice limits (CPU/memory/processes/IO) to every active
     * account on the package. The agent writes the slice unit AND calls
     * `systemctl set-property`, so running masters/workers pick the new caps up
     * immediately. Per-account failures are logged, never fatal to the save.
     */
    private function applyResourceLimits(int $packageId): string
    {
        $package = $this->find($packageId);
        if ($package === null) {
            return '';
        }
        $accounts = app('db')->select(
            'SELECT `username` FROM `accounts` WHERE `package_id` = ? AND `status` = ?',
            [$packageId, 'active']
        );
        if ($accounts === []) {
            return '';
        }
        $ok = 0;
        $failed = 0;
        foreach ($accounts as $account) {
            try {
                app('provisioning')->execute('systemd.slice.create', [
                    'name' => (string) $account['username'],
                    'memory_mb' => (int) ($package['max_memory_mb'] ?? 0),
                    'cpu_percent' => (int) ($package['max_cpu_percent'] ?? 100),
                    'tasks_max' => (int) ($package['max_processes'] ?? 100),
                    'io_mbs' => (int) ($package['max_io_mbs'] ?? 0),
                    'io_iops' => (int) ($package['max_iops'] ?? 0),
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                app('logger')->warning('Resource slice re-apply failed for ' . $account['username'] . ': ' . $e->getMessage());
            }
        }
        audit('package.resource_limits_applied', 'package', $packageId, ['applied' => $ok, 'failed' => $failed]);
        return " Resource caps applied to {$ok} account(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /**
     * Re-apply the inode ceiling for every active account on the package.
     * Bytes caps are unchanged; this is the same idempotent setquota call
     * used at provisioning time.
     */
    private function applyDiskQuota(int $packageId): string
    {
        $package = $this->find($packageId);
        if ($package === null) {
            return '';
        }
        $accounts = app('db')->select(
            'SELECT `username` FROM `accounts` WHERE `package_id` = ? AND `status` = ?',
            [$packageId, 'active']
        );
        if ($accounts === []) {
            return '';
        }
        $inodes = (int) ($package['disk_inodes'] ?? 0);
        $ok = 0;
        $failed = 0;
        foreach ($accounts as $account) {
            try {
                app('provisioning')->execute('quota.set', [
                    'username' => (string) $account['username'],
                    'soft_mb' => (int) ($package['disk_quota_mb'] ?? 0),
                    'hard_mb' => (int) ($package['disk_quota_mb'] ?? 0) + 100,
                    'inode_soft' => $inodes,
                    'inode_hard' => $inodes > 0 ? $inodes + 1000 : 0,
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                app('logger')->warning('Inode quota re-apply failed for ' . $account['username'] . ': ' . $e->getMessage());
            }
        }
        audit('package.inode_quota_applied', 'package', $packageId, ['inodes' => $inodes, 'applied' => $ok, 'failed' => $failed]);
        return " Inode limits applied to {$ok} account(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /** Re-provision pools so changed PHP ceilings reach running pools. */
    private function applyPhpLimits(int $packageId): string
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `package_id` = ? AND `status` = ?', [$packageId, 'active']);
        if ($accounts === []) {
            return '';
        }
        $svc = app(PhpVersionsService::class);
        [$ok, $failed] = [0, 0];
        foreach ($accounts as $account) {
            try {
                $sites = app('db')->select('SELECT `php_version`, `php_settings` FROM `websites` WHERE `account_id` = ? ORDER BY `id`', [(int) $account['id']]);
                $versions = [];
                foreach ($sites as $site) {
                    $v = PhpVersionsService::id((string) ($site['php_version'] ?? ''));
                    $versions[$v] ??= json_decode((string) ($site['php_settings'] ?? ''), true) ?: [];
                }
                if ($versions === []) {
                    $versions[PhpVersionsService::packageDefaultForAccount((int) $account['id'])] = [];
                }
                foreach ($versions as $version => $settings) {
                    $svc->ensurePool($account, (string) $version, $settings);
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                app('logger')->warning('PHP limit re-apply failed for ' . $account['username'] . ': ' . $e->getMessage());
            }
        }
        audit('package.php_limits_applied', 'package', $packageId, ['applied' => $ok, 'failed' => $failed]);
        return " PHP limits applied to {$ok} account(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /**
     * Stop every app of one runtime for the package's active accounts (the
     * package now allows zero). Data is kept; rows go 'stopped', so a later
     * re-enable needs an explicit start.
     */
    private function disableRuntime(int $packageId, string $runtime): string
    {
        $accounts = app('db')->select(
            'SELECT * FROM `accounts` WHERE `package_id` = ? AND `status` = ?',
            [$packageId, 'active']
        );
        if ($accounts === []) {
            return '';
        }
        $svc = app(\Modules\Applications\Services\AppService::class);
        [$ok, $failed] = [0, 0];
        foreach ($accounts as $account) {
            $apps = app('db')->select(
                'SELECT * FROM `applications` WHERE `account_id` = ? AND `runtime` = ?',
                [(int) $account['id'], $runtime]
            );
            foreach ($apps as $app) {
                try {
                    $svc->stop($account, $app);
                    app('db')->update('applications', [
                        'status' => 'stopped',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], '`id` = ?', [(int) $app['id']]);
                    $ok++;
                } catch (\Throwable $e) {
                    $failed++;
                    app('logger')->warning('Runtime disable stop failed for ' . $account['username'] . '/' . $app['name'] . ': ' . $e->getMessage());
                }
            }
        }
        audit('package.runtime_disabled', 'package', $packageId, ['runtime' => $runtime, 'stopped' => $ok, 'failed' => $failed]);

        return ' ' . ucfirst($runtime) . " apps disabled ({$ok} stopped)" . ($failed > 0 ? ", {$failed} failed." : '.');
    }

    /** Restart running apps so changed runtime memory caps take effect. */
    private function applyRuntimeMemory(int $packageId): string
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `package_id` = ? AND `status` = ?', [$packageId, 'active']);
        if ($accounts === []) {
            return '';
        }
        $svc = app(\Modules\Applications\Services\AppService::class);
        [$ok, $failed] = [0, 0];
        foreach ($accounts as $account) {
            $apps = app('db')->select(
                "SELECT * FROM `applications` WHERE `account_id` = ? AND `status` = 'running'",
                [(int) $account['id']]
            );
            foreach ($apps as $app) {
                try {
                    $svc->stop($account, $app);
                    $svc->start($account, $app, true);
                    $ok++;
                } catch (\Throwable $e) {
                    $failed++;
                    app('logger')->warning('Runtime memory re-apply failed for ' . $account['username'] . '/' . $app['name'] . ': ' . $e->getMessage());
                }
            }
        }
        audit('package.runtime_memory_applied', 'package', $packageId, ['applied' => $ok, 'failed' => $failed]);
        return " Runtime memory caps applied to {$ok} app(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /**
     * Regenerate sites that inherit the package's connection cap, then re-sync
     * the shared zone file. Ordering mirrors RateLimitService: zones must exist
     * before a vhost references them, and a vhost must stop referencing a zone
     * before that zone is dropped.
     */
    private function applyConnLimits(int $packageId): string
    {
        $sites = app('db')->select(
            'SELECT w.`id` FROM `websites` w
              JOIN `accounts` a ON a.`id` = w.`account_id`
              WHERE a.`package_id` = ? AND w.`status` = ? AND w.`max_connections` IS NULL',
            [$packageId, 'active']
        );
        $cap = (int) (app('db')->scalar('SELECT `max_connections` FROM `packages` WHERE `id` = ?', [$packageId]) ?? 0);
        $sync = app(\Modules\Websites\Services\RateLimitService::class);
        if ($cap > 0) {
            try {
                $sync->syncZones();
            } catch (\Throwable $e) {
                app('logger')->warning('Connection zone pre-sync failed: ' . $e->getMessage());
            }
        }
        $ok = 0;
        $failed = 0;
        foreach ($sites as $site) {
            try {
                app(\Modules\Websites\Services\PrivacyService::class)->regenerate((int) $site['id']);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                app('logger')->warning('Connection limit re-apply failed for website ' . $site['id'] . ': ' . $e->getMessage());
            }
        }
        if ($cap <= 0) {
            try {
                $sync->syncZones();
            } catch (\Throwable $e) {
                app('logger')->warning('Connection zone re-sync failed: ' . $e->getMessage());
            }
        }
        audit('package.conn_limit_applied', 'package', $packageId, ['cap' => $cap, 'applied' => $ok, 'failed' => $failed]);
        return " Connection limits applied to {$ok} site(s)" . ($failed > 0 ? ", {$failed} failed." : ".");
    }

    /**
     * Clamp over-ceiling mailboxes after a package quota change. Every
     * mailbox above $maxMb drops to it; one Dovecot resync at the end
     * publishes the new quota_rule set. 0 (unlimited) clamps nothing.
     * Data is never deleted — only the ceiling lowers.
     */
    private function applyMailboxQuota(int $packageId, int $maxMb): string
    {
        if ($maxMb <= 0) {
            return '';
        }
        $rows = app('db')->select(
            'SELECT m.`id` FROM `mailboxes` m JOIN `accounts` a ON a.`id` = m.`account_id`
              WHERE a.`package_id` = ? AND m.`quota_mb` > ?',
            [$packageId, $maxMb]
        );
        if ($rows === []) {
            return '';
        }
        $clamped = 0;
        foreach ($rows as $r) {
            try {
                app('db')->update('mailboxes', ['quota_mb' => $maxMb, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $r['id']]);
                $clamped++;
            } catch (\Throwable $e) {
                app('logger')->warning('Mailbox quota clamp failed for id ' . $r['id'] . ': ' . $e->getMessage());
            }
        }
        try {
            app(\Modules\Email\Services\MailService::class)->sync();
        } catch (\Throwable $e) {
            app('logger')->warning('Mail resync after quota clamp failed: ' . $e->getMessage());
        }
        audit('package.mailbox_quota_applied', 'package', $packageId, ['max_mb' => $maxMb, 'clamped' => $clamped]);
        return " {$clamped} mailbox(es) clamped to {$maxMb} MB.";
    }

    public function duplicate(int $id): Response
    {
        $package = $this->find($id);

        if ($package === null) {
            return Response::redirect('/admin/packages');
        }

        $data = [];
        foreach (array_merge(['name'], self::RESOURCE_FIELDS) as $field) {
            $data[$field] = $package[$field] ?? null;
            if (!in_array($field, self::COUNT_FIELDS, true) && $data[$field] === null) {
                $data[$field] = 0;
            }
        }

        $data['name'] = $data['name'] . ' (copy)';
        $data['description'] = $package['description'] ?? '';
        $data['slug'] = $package['slug'] . '-copy-' . substr(bin2hex(random_bytes(3)), 0, 4);
        $data['is_active'] = 1;
        $data['is_system'] = 0;

        $newId = $this->save(null, $data);

        $states = app(\Modules\Packages\Services\FeatureService::class)->forPackage($id);
        app(\Modules\Packages\Services\FeatureService::class)
            ->setForPackage($newId, array_keys(array_filter($states)));

        audit('package.duplicated', 'package', $newId, ['source' => $id]);
        app('session')->flash('success', 'Package duplicated.');

        return Response::redirect('/admin/packages');
    }

    public function destroy(int $id, Request $request): Response
    {
        $package = $this->find($id);

        if ($package === null) {
            return Response::redirect('/admin/packages');
        }

        if ((bool) $package['is_system']) {
            app('session')->flash('error', 'System packages cannot be deleted.');
            return Response::redirect('/admin/packages');
        }

        $accountCount = (int) app('db')->scalar('SELECT COUNT(*) FROM `accounts` WHERE `package_id` = ?', [$id]);

        if ($accountCount > 0) {
            app('session')->flash('error', "Package is assigned to {$accountCount} account(s) and cannot be deleted.");
            return Response::redirect('/admin/packages');
        }

        app('db')->delete('packages', '`id` = ?', [$id]);
        audit('package.deleted', 'package', $id, ['name' => $package['name']]);
        app('session')->flash('success', 'Package deleted.');

        return Response::redirect('/admin/packages');
    }

    private function form(?array $package, array $old): Response
    {
        $content = view('admin/packages/form', [
            'title' => $package ? 'Edit Package' : 'Add Package',
            'package' => $package,
            'old' => $old,
            'fields' => self::RESOURCE_FIELDS,
            'phpVersions' => \Modules\Core\Services\PhpVersionsService::AVAILABLE,
            'featureCatalog' => \Modules\Packages\Services\FeatureService::FEATURES,
            'featureStates' => $this->featureStates($package),
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    /**
     * Checkbox states for the feature fieldset: flashed input wins (failed
     * validation), then the stored package set, then all-on for new packages.
     *
     * @return array<string,bool>
     */
    private function featureStates(?array $package): array
    {
        $old = app('session')->get('_old', []);

        if (isset($old['features']) && is_array($old['features'])) {
            $on = array_fill_keys(array_map('strtolower', array_map('strval', $old['features'])), true);
            $states = [];
            foreach (\Modules\Packages\Services\FeatureService::keys() as $key) {
                $states[$key] = isset($on[$key]);
            }
            return $states;
        }

        if ($package === null) {
            return array_fill_keys(\Modules\Packages\Services\FeatureService::keys(), true);
        }

        return app(\Modules\Packages\Services\FeatureService::class)->forPackage((int) $package['id']);
    }

    private function rules(): array
    {
        return [
            'name' => 'required|max:191',
            'slug' => 'required|alpha_dash|max:191',
            'disk_quota_mb' => 'required|integer',
            'bandwidth_mb' => 'required|integer',
            'max_domains' => 'sometimes|integer',
            'max_subdomains' => 'sometimes|integer',
            'max_databases' => 'sometimes|integer',
            'max_email_accounts' => 'sometimes|integer',
            'max_ftp_accounts' => 'sometimes|integer',
            'max_cron_jobs' => 'sometimes|integer',
            'max_nodejs_apps' => 'sometimes|integer',
            'max_python_apps' => 'sometimes|integer',
            'max_cpu_percent' => 'required|integer',
            'max_memory_mb' => 'required|integer',
            'max_processes' => 'required|integer',
            'max_io_mbs' => 'required|integer',
            'disk_inodes' => 'sometimes|integer',
            'max_iops' => 'sometimes|integer',
            'php_max_children' => 'sometimes|integer',
            'php_memory_limit_mb' => 'sometimes|integer',
            'php_max_execution_time' => 'sometimes|integer',
            'max_node_memory_mb' => 'sometimes|integer',
            'max_python_memory_mb' => 'sometimes|integer',
            'database_quota_mb' => 'sometimes|integer',
            'max_connections' => 'sometimes|integer',
            'backup_quota_mb' => 'sometimes|integer',
            'nginx_workers' => 'required|integer',
            'nginx_max_body_mb' => 'required|integer',
            'nginx_req_per_sec' => 'required|integer',
            'nginx_req_burst' => 'required|integer',
            'mail_max_out_per_hour' => 'required|integer',
            'mail_max_out_per_day' => 'required|integer',
            'mailbox_max_out_per_hour' => 'required|integer',
            'mailbox_quota_max_mb' => 'required|integer',
            'mysql_max_user_connections' => 'required|integer',
            'mysql_max_queries_per_hour' => 'required|integer',
            'mysql_max_updates_per_hour' => 'required|integer',
            'mysql_max_statement_sec' => 'required|integer',
            'php_version' => 'sometimes|in:74,80,81,82,83,84',
        ];
    }

    /**
     * Range checks for limits the generic Validator cannot express (it only
     * knows required/integer/min/max string lengths). Values are integers at
     * this point; 0 always passes (unlimited).
     *
     * @return array<string,array<int,string>>
     */
    private const UNLIMITED_SENTINELS = [
        'php_max_children' => 64,          // PHP-FPM needs a finite pool
        'php_memory_limit_mb' => -1,       // PHP memory_limit=-1
        'php_max_execution_time' => -1,    // rendered as PHP 0 (no limit)
    ];

    /**
     * Apply the "Unlimited" checkboxes from the package form. Fields not
     * listed in UNLIMITED_SENTINELS store 0 (the engine's no-limit value);
     * the PHP trio uses its own sentinel (see the map).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyUnlimited(array $data, array $unlimited): array
    {
        foreach (array_keys($unlimited) as $field) {
            $field = (string) $field;
            if (!in_array($field, self::RESOURCE_FIELDS, true)) {
                continue;
            }
            $data[$field] = in_array($field, self::COUNT_FIELDS, true)
                ? null
                : (self::UNLIMITED_SENTINELS[$field] ?? 0);
        }

        // Un-ticking the box must also clear the sentinel that is still in the
        // number input (CSP forbids inline JS, so the reset happens here).
        foreach (self::UNLIMITED_SENTINELS as $field => $sentinel) {
            if (!array_key_exists($field, $unlimited) && array_key_exists($field, $data)
                && (int) $data[$field] === $sentinel) {
                $data[$field] = 0;
            }
        }

        return $data;
    }

    private function rangeErrors(array $data): array
    {
        $errors = [];
        $allowNegative = ['php_memory_limit_mb', 'php_max_execution_time'];
        foreach (self::LIMIT_RANGES as $field => $max) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = (int) $data[$field];
            if (in_array($field, $allowNegative, true) && $value === -1) {
                continue;
            }
            if ($value < 0 || $value > $max) {
                $errors[$field][] = "The {$field} must be between 0 and {$max}.";
            }
        }
        return $errors;
    }

    private function save(?int $id, array $data): int
    {
        $db = app('db');

        $columns = array_merge(['name', 'slug'], self::RESOURCE_FIELDS);
        $row = [];

        foreach ($columns as $column) {
            if ($column === 'name' || $column === 'slug') {
                $row[$column] = (string) ($data[$column] ?? '');
                continue;
            }
            $value = $data[$column] ?? null;
            // Count limits: NULL = unlimited, 0 = none allowed.
            $row[$column] = ($value === null || $value === '')
                ? (in_array($column, self::COUNT_FIELDS, true) ? null : 0)
                : (int) $value;
        }

        $row['description'] = $data['description'] ?? '';
        $row['is_active'] = isset($data['is_active']) ? 1 : 0;
        // Two-digit SCL id; keep the stored default when the field is absent
        // (API/partial updates) so a package edit never silently resets it.
        $existingPackage = $id !== null ? $this->find($id) : null;
        $row['php_version'] = \Modules\Core\Services\PhpVersionsService::isAvailable((string) ($data['php_version'] ?? ''))
            ? \Modules\Core\Services\PhpVersionsService::id((string) $data['php_version'])
            : (string) ($existingPackage['php_version'] ?? '84');

        if ($id === null) {
            $row['is_system'] = 0;
            $row['created_at'] = date('Y-m-d H:i:s');
            $row['updated_at'] = date('Y-m-d H:i:s');
            $id = $db->insert('packages', $row);
        } else {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->update('packages', $row, '`id` = ?', [$id]);
        }

        return $id;
    }

    private function find(int $id): ?array
    {
        return app('db')->selectOne('SELECT * FROM `packages` WHERE `id` = ?', [$id]);
    }

    private function backWithErrors(Request $request, ValidationException $e, string $path): Response
    {
        app('session')->flashInput($request->all());
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $msg) {
                app('session')->pushError($field, $msg);
            }
        }

        return Response::redirect($path);
    }
}
