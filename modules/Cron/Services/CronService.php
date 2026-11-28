<?php
declare(strict_types=1);
namespace Modules\Cron\Services;

/**
 * Syncs panel cron jobs to the account's system crontab via the agent.
 *
 * The agent writes /var/spool/cron/<username>; crond watches the spool
 * via inotify and picks up changes on its own (no service restart — a
 * restart here only created downtime windows the watchdog flagged). Only
 * enabled jobs are included; the crontab is rebuilt from the panel DB on
 * every change. Every line runs through cwhpanel-cron-run (same shell
 * semantics as a bare line), which records exit/output/duration as JSON
 * under ~/.cwhpanel-cron/<jobid>.json for the list pages to display.
 */
class CronService
{
    public const WRAPPER = '/usr/local/bin/cwhpanel-cron-run';

    /** Wrapper-prefixed crontab line for one enabled job row. */
    public static function wrapLine(array $job): string
    {
        // Re-validate at generation time: a cron row written by any path
        // (migration, installer, API) must never inject extra crontab lines.
        self::assertSingleLine((string) $job['schedule'], 'schedule');
        self::assertSingleLine((string) $job['command'], 'command');
        return $job['schedule'] . ' ' . self::WRAPPER . ' ' . (int) $job['id']
            . ' ' . escapeshellarg((string) $job['command']);
    }

    /**
     * Reject control characters that would break out of a crontab line
     * (a newline in schedule/command injects whole extra lines).
     */
    public static function assertSingleLine(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
            throw new \RuntimeException("Invalid {$field} (single line only).");
        }
        return $value;
    }

    private function accountUsername(int $accountId): ?string
    {
        $row = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `id` = ?', [$accountId]);
        return $row ? (string) $row['username'] : null;
    }

    public function sync(int $accountId): bool
    {
        $username = $this->accountUsername($accountId);
        if ($username === null) {
            return true;
        }

        $jobs = app('db')->select(
            'SELECT * FROM `cron_jobs` WHERE `account_id` = ? AND `status` = ? ORDER BY `id`',
            [$accountId, 'enabled']
        );

        $normal = [];
        $jailed = [];
        foreach ($jobs as $job) {
            if ((int) ($job['jailed'] ?? 0) === 1) {
                $jailed[] = $job;
            } else {
                $normal[] = $job;
            }
        }

        // Jailed jobs run via a root cron.d dispatcher; normal jobs use the
        // account's own spool (crond runs them as the account).
        if ($normal === []) {
            app('provisioning')->execute('cron.clear', ['username' => $username]);
        } else {
            $lines = [];
            foreach ($normal as $job) {
                $lines[] = self::wrapLine($job);
            }
            $result = app('provisioning')->execute('cron.set', [
                'username' => $username,
                'entries' => implode("\n", $lines) . "\n",
            ]);
            if ($result['status'] !== 'success') {
                return false;
            }
        }

        if ($jailed === []) {
            try {
                app('provisioning')->execute('cron.jailed.clear', ['username' => $username]);
            } catch (\Throwable $e) {
                try { app('logger')->warning("cron.jailed.clear failed for {$username}: " . $e->getMessage()); } catch (\Throwable) {}
            }
        } else {
            $lines = [];
            foreach ($jailed as $job) {
                $lines[] = $this->wrapJailedLine($username, $job);
            }
            $result = app('provisioning')->execute('cron.jailed.set', [
                'username' => $username,
                'entries' => implode("\n", $lines) . "\n",
            ]);
            if ($result['status'] !== 'success') {
                return false;
            }
        }

        return true;
    }

    /** Root cron.d line for a jailed job (<schedule> root …dispatcher…). */
    private function wrapJailedLine(string $username, array $job): string
    {
        self::assertSingleLine((string) $job['schedule'], 'schedule');
        self::assertSingleLine((string) $job['command'], 'command');
        // The command rides base64 so crond's own shell re-parsing of the
        // crontab line can never mangle quotes/metacharacters; the root
        // dispatcher decodes it before exec.
        $b64 = base64_encode((string) $job['command']);
        return $job['schedule'] . ' root /usr/local/bin/cwhpanel-cron-jail '
            . $username . ' ' . (int) $job['id'] . ' ' . $b64;
    }

    /**
     * Delete one job row, its result state, and resync. Shared by the
     * admin/customer destroy actions (previously duplicated).
     */
    public function deleteJob(int $accountId, int $jobId): void
    {
        $username = $this->accountUsername($accountId);
        app('db')->delete('cron_jobs', '`id` = ? AND `account_id` = ?', [$jobId, $accountId]);
        if ($username !== null) {
            try {
                app('provisioning')->execute('file.delete', [
                    'account' => $username,
                    'path' => '.cwhpanel-cron/' . $jobId . '.json',
                ]);
            } catch (\Throwable $ignored) {
                // Never ran (or already gone) — not an error.
            }
        }
        $this->sync($accountId);
        audit('cron.deleted', 'cron_job', $jobId);
    }

    /**
     * Pull wrapper state files into the DB columns (one bulk op per
     * account). Best-effort per job: missing/garbled files mean "never
     * ran" and are skipped silently. Returns refreshed count.
     */
    public function refreshFromState(int $accountId): int
    {
        $username = $this->accountUsername($accountId);
        if ($username === null) {
            return 0;
        }
        try {
            $r = app('provisioning')->execute('cron.state.read', ['username' => $username]);
        } catch (\Throwable $e) {
            return 0; // agent down / no state dir yet: keep last-known DB values.
        }
        $states = [];
        $parts = explode("\0", (string) ($r['output'] ?? ''));
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $name = trim($parts[$i]);
            if (preg_match('/^\d{1,20}$/', $name) !== 1) {
                continue;
            }
            $decoded = json_decode($parts[$i + 1], true);
            if (is_array($decoded)) {
                $states[(int) $name] = $decoded;
            }
        }
        $n = 0;
        foreach ($states as $jobId => $st) {
            if (!isset($st['ran_at']) || !is_numeric($st['ran_at'])) {
                continue;
            }
            try {
                $updated = app('db')->update('cron_jobs', [
                    'last_run_at' => date('Y-m-d H:i:s', (int) $st['ran_at']),
                    'last_output' => isset($st['output']) ? mb_substr((string) $st['output'], 0, 8000) : null,
                    'last_exit' => isset($st['exit']) ? (int) $st['exit'] : null,
                    'last_duration_ms' => isset($st['duration_ms']) ? (int) $st['duration_ms'] : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ? AND `account_id` = ?', [$jobId, $accountId]);
                $n += $updated > 0 ? 1 : 0;
            } catch (\Throwable $ignored) {
                // Pre-migration columns: skip, the page still renders.
            }
        }
        return $n;
    }

    /**
     * Execute one job NOW through the wrapper (same shell semantics as
     * its crontab line) and refresh its row. Returns exit/output/duration.
     *
     * @return array{exit:int, output:string, duration_ms:int}
     */
    public function runNow(int $accountId, int $jobId): array
    {
        $job = app('db')->selectOne(
            'SELECT * FROM `cron_jobs` WHERE `id` = ? AND `account_id` = ?',
            [$jobId, $accountId]
        );
        if ($job === null) {
            throw new \RuntimeException('Cron job not found.');
        }
        $username = $this->accountUsername($accountId);
        if ($username === null) {
            throw new \RuntimeException('Account not found.');
        }
        $jailed = (int) ($job['jailed'] ?? 0) === 1;
        if ($jailed) {
            // The jail skeleton must exist; idempotent.
            app('provisioning')->execute('app.jail.ensure', ['account' => $username]);
            app('provisioning')->execute('cron.run.jailed', [
                'account' => $username,
                'job_id' => (string) $jobId,
                'command' => (string) $job['command'],
            ]);
        } else {
            app('provisioning')->execute('cron.run', [
                'username' => $username,
                'job_id' => (string) $jobId,
                'command' => (string) $job['command'],
            ]);
        }
        $this->refreshFromState($accountId);
        $job = app('db')->selectOne('SELECT * FROM `cron_jobs` WHERE `id` = ?', [$jobId]);
        audit('cron.run', 'cron_job', $jobId, ['exit' => (int) ($job['last_exit'] ?? -1)]);
        return [
            'exit' => (int) ($job['last_exit'] ?? -1),
            'output' => (string) ($job['last_output'] ?? ''),
            'duration_ms' => (int) ($job['last_duration_ms'] ?? 0),
        ];
    }
}