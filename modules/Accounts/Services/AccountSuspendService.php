<?php

declare(strict_types=1);

namespace Modules\Accounts\Services;

/**
 * AccountSuspendService — full-halt suspend / reverse unsuspend.
 *
 * Admin suspend used to be SSH-only (user.lock): websites kept serving,
 * apps kept running, cron kept firing and mail kept delivering — weaker
 * than even the bandwidth billing hold. Suspend now halts everything:
 *
 *   - system login locked (existing user.lock/unlock)
 *   - every website parked (websites.suspended_account + vhost regen → 503;
 *     rides the maintenance path but is admin-only to clear, and the
 *     customer's own maintenance_mode underneath is preserved)
 *   - running app units stopped (prior-running ids snapshotted)
 *   - enabled cron rows paused + crontab resynced (prior-enabled snapshotted)
 *   - active mailboxes held (status → suspended drops them from the
 *     postfix/dovecot maps so inbound bounces; prior-active snapshotted)
 *   - FTP logins locked (per-row system users; prior-active snapshotted;
 *     the account lock alone would not stop them since each row carries
 *     its own password)
 *
 * Resume state lives in accounts.suspend_state (JSON ids) so unsuspend
 * restores exactly what suspend took, even across restarts. Terminate is
 * unaffected (it destroys rather than parks). Bandwidth holds use the
 * separate suspended_bandwidth flag and are untouched by either path.
 *
 * Every leg is best-effort per item (logged, never fatal to the whole).
 */
class AccountSuspendService
{
    /** @return array{parked:int, apps:int, crons:int, mailboxes:int, ftp:int} */
    public function suspend(array $account): array
    {
        $accountId = (int) $account['id'];
        $username = (string) $account['username'];
        $done = ['parked' => 0, 'apps' => 0, 'crons' => 0, 'mailboxes' => 0, 'ftp' => 0];
        // Merge into any existing snapshot (double-suspend must not orphan
        // the original resume state by overwriting it with empties).
        $state = ['apps' => [], 'crons' => [], 'mailboxes' => [], 'ftp' => []];
        try {
            $raw = app('db')->selectOne('SELECT `suspend_state` FROM `accounts` WHERE `id` = ?', [$accountId]);
            $decoded = json_decode((string) ($raw['suspend_state'] ?? ''), true);
            if (is_array($decoded)) {
                foreach (['apps', 'crons', 'mailboxes', 'ftp'] as $k) {
                    $state[$k] = array_values(array_filter(array_map('intval', (array) ($decoded[$k] ?? []))));
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            app(\Modules\Accounts\Services\AccountProvisioner::class)->suspend($username);
        } catch (\Throwable $e) {
            app('logger')->warning("Suspend lock failed for {$username}: " . $e->getMessage());
        }

        $regen = app(\Modules\Websites\Services\PrivacyService::class);
        foreach (app('db')->select('SELECT `id` FROM `websites` WHERE `account_id` = ?', [$accountId]) as $site) {
            try {
                app('db')->update('websites', [
                    'suspended_account' => 1, 'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [(int) $site['id']]);
                $regen->regenerate((int) $site['id']);
                $done['parked']++;
            } catch (\Throwable $e) {
                app('logger')->warning('Suspend park failed for website ' . $site['id'] . ': ' . $e->getMessage());
            }
        }

        $appSvc = app(\Modules\Applications\Services\AppService::class);
        foreach (app('db')->select("SELECT * FROM `applications` WHERE `account_id` = ? AND `status` = 'running'", [$accountId]) as $appRow) {
            try {
                $appSvc->stop($account, $appRow);
                app('db')->update('applications', ['status' => 'stopped', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $appRow['id']]);
                if (!in_array((int) $appRow['id'], $state['apps'], true)) {
                    $state['apps'][] = (int) $appRow['id'];
                }
                $done['apps']++;
            } catch (\Throwable $e) {
                app('logger')->warning('Suspend app-stop failed for app ' . $appRow['id'] . ': ' . $e->getMessage());
            }
        }

        $cronSvc = app(\Modules\Cron\Services\CronService::class);
        foreach (app('db')->select("SELECT `id` FROM `cron_jobs` WHERE `account_id` = ? AND `status` = 'enabled'", [$accountId]) as $job) {
            try {
                app('db')->update('cron_jobs', ['status' => 'disabled', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $job['id']]);
                if (!in_array((int) $job['id'], $state['crons'], true)) {
                    $state['crons'][] = (int) $job['id'];
                }
                $done['crons']++;
            } catch (\Throwable $e) {
                app('logger')->warning('Suspend cron-pause failed for job ' . $job['id'] . ': ' . $e->getMessage());
            }
        }
        if ($state['crons'] !== []) {
            try {
                $cronSvc->sync($accountId);
            } catch (\Throwable $e) {
                app('logger')->warning("Suspend crontab resync failed for {$username}: " . $e->getMessage());
            }
        }

        foreach (app('db')->select("SELECT `id` FROM `mailboxes` WHERE `account_id` = ? AND `status` = 'active'", [$accountId]) as $mb) {
            try {
                app('db')->update('mailboxes', ['status' => 'suspended', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $mb['id']]);
                if (!in_array((int) $mb['id'], $state['mailboxes'], true)) {
                    $state['mailboxes'][] = (int) $mb['id'];
                }
                $done['mailboxes']++;
            } catch (\Throwable $e) {
                app('logger')->warning('Suspend mailbox-hold failed for mailbox ' . $mb['id'] . ': ' . $e->getMessage());
            }
        }
        if ($state['mailboxes'] !== []) {
            try {
                app(\Modules\Email\Services\MailService::class)->sync();
            } catch (\Throwable $e) {
                app('logger')->warning("Suspend mail resync failed for {$username}: " . $e->getMessage());
            }
        }

        $ftpSvc = app(\Modules\Ftp\Services\FtpService::class);
        foreach (app('db')->select("SELECT `id`, `username` FROM `ftp_accounts` WHERE `account_id` = ? AND `status` = 'active'", [$accountId]) as $frow) {
            try {
                $ftpSvc->lock((string) $frow['username']);
                app('db')->update('ftp_accounts', ['status' => 'suspended', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $frow['id']]);
                if (!in_array((int) $frow['id'], $state['ftp'], true)) {
                    $state['ftp'][] = (int) $frow['id'];
                }
                $done['ftp']++;
            } catch (\Throwable $e) {
                app('logger')->warning('Suspend FTP lock failed for row ' . $frow['id'] . ': ' . $e->getMessage());
            }
        }

        try {
            app('db')->update('accounts', ['suspend_state' => json_encode($state), 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$accountId]);
        } catch (\Throwable $e) {
            app('logger')->warning("Suspend state snapshot failed for {$username}: " . $e->getMessage());
        }

        // Observable completeness: anything that should now be halted but is
        // not counts as a failed leg, so the caller can surface a warning
        // instead of silently reporting a clean suspension.
        try {
            $done['errors'] = 0
                + (int) app('db')->scalar('SELECT COUNT(*) FROM `websites` WHERE `account_id` = ? AND `suspended_account` = 0', [$accountId])
                + (int) app('db')->scalar("SELECT COUNT(*) FROM `applications` WHERE `account_id` = ? AND `status` = 'running'", [$accountId])
                + (int) app('db')->scalar("SELECT COUNT(*) FROM `cron_jobs` WHERE `account_id` = ? AND `status` = 'enabled'", [$accountId])
                + (int) app('db')->scalar("SELECT COUNT(*) FROM `mailboxes` WHERE `account_id` = ? AND `status` = 'active'", [$accountId])
                + (int) app('db')->scalar("SELECT COUNT(*) FROM `ftp_accounts` WHERE `account_id` = ? AND `status` = 'active'", [$accountId]);
        } catch (\Throwable $e) {
            $done['errors'] = -1;
        }

        return $done;
    }

    /** @return array{parked:int, apps:int, crons:int, mailboxes:int, ftp:int} */
    public function unsuspend(array $account): array
    {
        $accountId = (int) $account['id'];
        $username = (string) $account['username'];
        $done = ['parked' => 0, 'apps' => 0, 'crons' => 0, 'mailboxes' => 0, 'ftp' => 0];
        $state = ['apps' => [], 'crons' => [], 'mailboxes' => [], 'ftp' => []];
        try {
            $raw = app('db')->selectOne('SELECT `suspend_state` FROM `accounts` WHERE `id` = ?', [$accountId]);
            $decoded = json_decode((string) ($raw['suspend_state'] ?? ''), true);
            if (is_array($decoded)) {
                foreach (['apps', 'crons', 'mailboxes', 'ftp'] as $k) {
                    $state[$k] = array_values(array_filter(array_map('intval', (array) ($decoded[$k] ?? []))));
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            app(\Modules\Accounts\Services\AccountProvisioner::class)->unsuspend($username);
        } catch (\Throwable $e) {
            app('logger')->warning("Unsuspend unlock failed for {$username}: " . $e->getMessage());
        }

        $regen = app(\Modules\Websites\Services\PrivacyService::class);
        foreach (app('db')->select('SELECT `id` FROM `websites` WHERE `account_id` = ? AND `suspended_account` = 1', [$accountId]) as $site) {
            try {
                app('db')->update('websites', [
                    'suspended_account' => 0, 'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [(int) $site['id']]);
                $regen->regenerate((int) $site['id']);
                $done['parked']++;
            } catch (\Throwable $e) {
                app('logger')->warning('Unsuspend unpark failed for website ' . $site['id'] . ': ' . $e->getMessage());
            }
        }

        $appSvc = app(\Modules\Applications\Services\AppService::class);
        foreach ($state['apps'] as $appId) {
            $appRow = app('db')->selectOne('SELECT * FROM `applications` WHERE `id` = ? AND `account_id` = ?', [$appId, $accountId]);
            if ($appRow === null) {
                continue;
            }
            try {
                $appSvc->start($account, $appRow);
                $done['apps']++;
            } catch (\Throwable $e) {
                app('logger')->warning('Unsuspend app-start failed for app ' . $appId . ': ' . $e->getMessage());
            }
        }

        if ($state['crons'] !== []) {
            try {
                app('db')->statement(
                    'UPDATE `cron_jobs` SET `status` = ?, `updated_at` = ? WHERE `account_id` = ? AND `id` IN ('
                    . implode(',', array_fill(0, count($state['crons']), '?')) . ')',
                    array_merge(['enabled', date('Y-m-d H:i:s'), $accountId], $state['crons'])
                );
                $done['crons'] = count($state['crons']);
                app(\Modules\Cron\Services\CronService::class)->sync($accountId);
            } catch (\Throwable $e) {
                app('logger')->warning("Unsuspend cron-resume failed for {$username}: " . $e->getMessage());
            }
        }

        if ($state['mailboxes'] !== []) {
            try {
                app('db')->statement(
                    'UPDATE `mailboxes` SET `status` = ?, `updated_at` = ? WHERE `account_id` = ? AND `id` IN ('
                    . implode(',', array_fill(0, count($state['mailboxes']), '?')) . ')',
                    array_merge(['active', date('Y-m-d H:i:s'), $accountId], $state['mailboxes'])
                );
                $done['mailboxes'] = count($state['mailboxes']);
                app(\Modules\Email\Services\MailService::class)->sync();
            } catch (\Throwable $e) {
                app('logger')->warning("Unsuspend mailbox-restore failed for {$username}: " . $e->getMessage());
            }
        }

        try {
            app('db')->update('accounts', ['suspend_state' => null, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$accountId]);
        } catch (\Throwable $e) {
        }

        if ($state['ftp'] !== []) {
            $ftpSvc = app(\Modules\Ftp\Services\FtpService::class);
            $resumed = 0;
            foreach ($state['ftp'] as $ftpId) {
                $frow = app('db')->selectOne('SELECT `username` FROM `ftp_accounts` WHERE `id` = ? AND `account_id` = ?', [$ftpId, $accountId]);
                if ($frow === null) {
                    continue;
                }
                try {
                    $ftpSvc->unlock((string) $frow['username']);
                    app('db')->update('ftp_accounts', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$ftpId]);
                    $resumed++;
                } catch (\Throwable $e) {
                    app('logger')->warning('Unsuspend FTP unlock failed for row ' . $ftpId . ': ' . $e->getMessage());
                }
            }
            $done['ftp'] = $resumed;
        }

        return $done;
    }
}
