<?php

declare(strict_types=1);

namespace Modules\Packages\Services;

/**
 * Bandwidth enforcement (per hosting package).
 *
 * A daily sweep (scripts/bandwidth-sweep.php cron) totals each active
 * account's served bytes via TrafficService and compares against the
 * package's bandwidth_mb:
 *   - 80% / 95%: warning email to the account email (once per flag/month).
 *   - >= 100%: all active sites get suspended_bandwidth=1 (503 through the
 *     same generator path as maintenance mode) + suspension email.
 *   - back under quota (new month, upgraded package): flags cleared, sites
 *     re-published. Only admins can clear a hold mid-month (unsuspend in
 *     AdminWebsiteController); the next sweep re-suspends while still over.
 *
 * Packages with bandwidth_mb = 0 are unlimited (fail-open, like other caps).
 * Usage is monotonic within a month (max of samples) so log rotation can
 * never shrink it. All mail is best-effort and logged, never fatal.
 */
class BandwidthService
{
    public const NOTIFY_80 = 0.80;
    public const NOTIFY_95 = 0.95;

    /**
     * Sweep all active accounts. Returns [accounts, suspended, unsuspended, mailed].
     *
     * Accounting is incremental: each website's logs are tailed (cap-safe
     * 60 KiB reads — the agent kills larger outputs) and only entries newer
     * than the stored per-site cursor are added to the month total. Rotation
     * can never shrink the counter; clock-ordered timestamps make re-reads
     * idempotent (a repeated sweep adds ~0).
     */
    public function sweep(?string $month = null): array
    {
        $month = $month ?: date('Y-m');
        $stats = ['accounts' => 0, 'suspended' => 0, 'unsuspended' => 0, 'mailed' => 0];
        $traffic = app(\Modules\Monitoring\Services\TrafficService::class);
        $accounts = app('db')->select(
            'SELECT a.`id`, a.`username`, a.`email`, p.`bandwidth_mb`
             FROM `accounts` a JOIN `packages` p ON p.`id` = a.`package_id`
             WHERE a.`status` = ?',
            ['active']
        );
        foreach ($accounts as $account) {
            $limitMb = (int) ($account['bandwidth_mb'] ?? 0);
            if ($limitMb <= 0) {
                continue; // unlimited package.
            }
            $stats['accounts']++;
            [$delta, $readOk] = $this->accountDelta((int) $account['id'], $traffic);
            if (!$readOk) {
                continue; // all log reads failed; try again next sweep.
            }
            $row = $this->usageRow((int) $account['id'], $month, $delta);
            $row = $this->usageRow((int) $account['id'], $month, $delta);
            $bytes = (int) $row['bytes'];
            $limit = $limitMb * 1048576;
            $ratio = $limit > 0 ? $bytes / $limit : 0;
            // NOTE: never use float thresholds as array keys (PHP casts them
            // to int and collapses the map to a single entry).
            foreach ([[self::NOTIFY_80, 'notified80'], [self::NOTIFY_95, 'notified95']] as [$threshold, $flag]) {
                if ($ratio >= $threshold && empty($row[$flag])) {
                    if ($this->mail($account, $threshold, $bytes, $limitMb, false)) {
                        $stats['mailed']++;
                    }
                    app('db')->update('bandwidth_usage', [$flag => 1, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $row['id']]);
                    $row[$flag] = 1;
                }
            }
            if ($bytes >= $limit && empty($row['suspended'])) {
                $n = $this->setSuspended((int) $account['id'], true);
                app('db')->update('bandwidth_usage', ['suspended' => 1, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $row['id']]);
                audit('bandwidth.suspended', 'account', (int) $account['id'], ['mb' => round($bytes / 1048576, 1), 'limit_mb' => $limitMb]);
                if ($this->mail($account, 1.0, $bytes, $limitMb, true)) {
                    $stats['mailed']++;
                }
                $stats['suspended'] += $n > 0 ? 1 : 0;
                try {
                    app(\Modules\Core\Services\NotificationService::class)->pushAccountOwner(
                        (string) $account['username'],
                        'bandwidth.suspended',
                        'Bandwidth exceeded — websites suspended',
                        'Account used ' . round($bytes / 1048576, 1) . ' MB of ' . $limitMb . ' MB this month.',
                        '/panel'
                    );
                } catch (\Throwable $e) {
                }
            } elseif ($bytes < $limit && !empty($row['suspended'])) {
                $n = $this->setSuspended((int) $account['id'], false);
                app('db')->update('bandwidth_usage', ['suspended' => 0, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $row['id']]);
                audit('bandwidth.unsuspended', 'account', (int) $account['id'], ['mb' => round($bytes / 1048576, 1)]);
                $stats['unsuspended'] += $n > 0 ? 1 : 0;
            }
        }
        return $stats;
    }

    /**
     * New bytes since the last sweep, per website cursors.
     * Returns [delta, readOk]; readOk=false only when every log read failed.
     */
    private function accountDelta(int $accountId, \Modules\Monitoring\Services\TrafficService $traffic): array
    {
        $websites = app('db')->select(
            'SELECT * FROM `websites` WHERE `account_id` = ? AND `status` = ? ORDER BY `id`',
            [$accountId, 'active']
        );
        $total = 0;
        $readOk = false;
        $seenPaths = [];
        foreach ($websites as $website) {
            try {
                $paths = $traffic->logPaths($website);
            } catch (\Throwable $e) {
                continue;
            }
            $cursor = app('db')->selectOne(
                'SELECT * FROM `bandwidth_cursors` WHERE `website_id` = ?',
                [(int) $website['id']]
            );
            $lastTs = $cursor !== null ? (int) $cursor['last_ts'] : 0;
            $maxTs = $lastTs;
            foreach ($paths as $path) {
                // Stale duplicate rows can resolve to the same file: count
                // each path once per sweep.
                if (isset($seenPaths[$path])) {
                    continue;
                }
                $seenPaths[$path] = true;
                try {
                    $result = app('provisioning')->execute('log.read', ['path' => $path, 'max_bytes' => 60000]);
                } catch (\Throwable $e) {
                    continue;
                }
                if (($result['status'] ?? '') !== 'success') {
                    continue;
                }
                $readOk = true;
                foreach ($this->parseLines((string) ($result['output'] ?? '')) as [$ts, $bytes]) {
                    if ($ts > $lastTs) {
                        $total += $bytes;
                    }
                    if ($ts > $maxTs) {
                        $maxTs = $ts;
                    }
                }
            }
            if ($maxTs > $lastTs) {
                if ($cursor === null) {
                    try {
                        app('db')->insert('bandwidth_cursors', [
                            'website_id' => (int) $website['id'], 'last_ts' => $maxTs,
                            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    } catch (\Throwable $e) {
                    }
                } else {
                    app('db')->update('bandwidth_cursors', [
                        'last_ts' => $maxTs, 'updated_at' => date('Y-m-d H:i:s'),
                    ], '`website_id` = ?', [(int) $website['id']]);
                }
            }
        }
        return [$total, $readOk];
    }

    /**
     * Combined-log lines => [epoch, bytes]. Unparseable lines are skipped
     * (never fatal to the sweep); '-' byte counts as 0.
     *
     * @return array<int, array{0:int, 1:int}>
     */
    public function parseLines(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/\[([^\]]+)\]\s+"[^"]*"\s+(\d{3})\s+(\S+)/', $line, $m)) {
                continue;
            }
            $dt = \DateTime::createFromFormat('d/M/Y:H:i:s O', $m[1]);
            if ($dt === false) {
                continue;
            }
            $out[] = [$dt->getTimestamp(), $m[3] === '-' ? 0 : max(0, (int) $m[3])];
        }
        return $out;
    }
    /** Month usage row, creating it; $delta is added (bytes only grow). */
    public function usageRow(int $accountId, string $month, int $delta): array
    {
        $row = app('db')->selectOne(
            'SELECT * FROM `bandwidth_usage` WHERE `account_id` = ? AND `month` = ?',
            [$accountId, $month]
        );
        if ($row === null) {
            try {
                $id = app('db')->insert('bandwidth_usage', [
                    'account_id' => $accountId, 'month' => $month, 'bytes' => max(0, $delta),
                    'notified80' => 0, 'notified95' => 0, 'suspended' => 0,
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                // Lost insert race (duplicate month row): fall through to select.
                $id = 0;
            }
            if (!empty($id)) {
                return array_merge(['id' => $id], ['account_id' => $accountId, 'month' => $month, 'bytes' => max(0, $delta), 'notified80' => 0, 'notified95' => 0, 'suspended' => 0]);
            }
            $row = app('db')->selectOne(
                'SELECT * FROM `bandwidth_usage` WHERE `account_id` = ? AND `month` = ?',
                [$accountId, $month]
            );
        }
        if ($row !== null && $delta > 0) {
            $newTotal = (int) $row['bytes'] + $delta;
            app('db')->update('bandwidth_usage', ['bytes' => $newTotal, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $row['id']]);
            $row['bytes'] = $newTotal;
        }
        return $row ?? ['id' => 0, 'bytes' => max(0, $delta), 'notified80' => 0, 'notified95' => 0, 'suspended' => 0];
    }

    /**
     * (Un)set the billing hold on every active site; regenerates through the
     * isolation-aware path so both serving paths agree. Returns sites touched.
     */
    public function setSuspended(int $accountId, bool $on): int
    {
        $n = 0;
        $regen = app(\Modules\Websites\Services\PrivacyService::class);
        foreach (app('db')->select('SELECT `id` FROM `websites` WHERE `account_id` = ? AND `status` = ?', [$accountId, 'active']) as $site) {
            try {
                app('db')->update('websites', [
                    'suspended_bandwidth' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [(int) $site['id']]);
                $regen->regenerate((int) $site['id']);
                $n++;
            } catch (\Throwable $e) {
                app('logger')->warning('Bandwidth hold failed for website ' . $site['id'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** Current month usage for one account (bytes, limit, ratio), or null. */
    public function usageForAccount(int $accountId): ?array
    {
        $month = date('Y-m');
        $row = app('db')->selectOne(
            'SELECT u.*, p.`bandwidth_mb` FROM `bandwidth_usage` u
             JOIN `accounts` a ON a.`id` = u.`account_id`
             JOIN `packages` p ON p.`id` = a.`package_id`
             WHERE u.`account_id` = ? AND u.`month` = ?',
            [$accountId, $month]
        );
        if ($row === null) {
            return null;
        }
        $limit = (int) ($row['bandwidth_mb'] ?? 0) * 1048576;
        return [
            'bytes' => (int) $row['bytes'], 'limit' => $limit,
            'ratio' => $limit > 0 ? (int) $row['bytes'] / $limit : 0,
            'suspended' => !empty($row['suspended']),
        ];
    }

    private function mail(array $account, float $threshold, int $bytes, int $limitMb, bool $suspended): bool
    {
        $to = trim((string) ($account['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $mb = round($bytes / 1048576, 1);
        $pct = (int) round($threshold * 100);
        $subject = $suspended
            ? "[hosting] Bandwidth exceeded — sites suspended ({$account['username']})"
            : "[hosting] Bandwidth at {$pct}% ({$account['username']})";
        $body = $suspended
            ? "Account {$account['username']} used {$mb} MB of {$limitMb} MB this month.\nAll websites now answer 503 until usage drops (new month or upgraded package).\n"
            : "Account {$account['username']} used {$mb} MB of {$limitMb} MB ({$pct}%) this month.\nAt 100% the websites are suspended automatically.\n";
        $host = gethostname() ?: 'server';
        $ok = (bool) @mail($to, $subject, $body, "From: cwhpanel@{$host}\r\nX-Auto-Response-Suppress: All\r\n");
        if (!$ok) {
            app('logger')->warning("Bandwidth mail to {$to} failed.");
        }
        return $ok;
    }
}
