<?php

declare(strict_types=1);

namespace Modules\Packages\Controllers\Package;

use Modules\Core\Services\PhpVersionsService;

/**
 * Live propagation helpers for PackageController::update().
 *
 * When an administrator changes package resource ceilings or disables an
 * execution feature, these methods converge the package's active accounts
 * immediately (slices, quotas, PHP masters, nginx workers, rate-limit zones,
 * MySQL Governor, mailbox quotas, runtime apps).
 */
trait ApplyLimitsTrait
{
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
                try { app('logger')->warning('Rate-limit zone sync failed: ' . $e->getMessage()); } catch (\Throwable) {}
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

    /** Re-provision masters so changed PHP ceilings reach running pools. */
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
}
