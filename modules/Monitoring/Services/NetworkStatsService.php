<?php

declare(strict_types=1);

namespace Modules\Monitoring\Services;

use Modules\Core\Services\SystemInfo;

/**
 * Network + bandwidth statistics for the admin Monitoring area.
 *
 * Live per-interface counters come from the agent (`network.stats`, raw
 * /proc/net/dev); rates are computed from two quick samples. Per-account
 * monthly bandwidth comes from the `bandwidth_usage` table maintained by
 * BandwidthService (nginx-log based), joined to packages for limits.
 */
class NetworkStatsService
{
    /** Parse /proc/net/dev text into per-interface counter rows. */
    public function interfaces(?string $raw = null): array
    {
        if ($raw === null) {
            try {
                $raw = (string) (app('provisioning')->execute('network.stats', [])['output'] ?? '');
            } catch (\Throwable $e) {
                throw new \RuntimeException('Agent network stats unavailable: ' . $e->getMessage());
            }
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (!preg_match('/^\s*(\S+):\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/', $line, $m)) {
                continue;
            }
            $out[] = [
                'name' => $m[1],
                'rx_bytes' => (int) $m[2],
                'rx_packets' => (int) $m[3],
                'rx_errors' => (int) $m[4],
                'rx_drop' => (int) $m[5],
                'tx_bytes' => (int) $m[10],
                'tx_packets' => (int) $m[11],
                'tx_errors' => (int) $m[12],
                'tx_drop' => (int) $m[13],
            ];
        }
        return $out;
    }

    /**
     * Live rates per interface (bytes/second) from two samples ~$intervalSec
     * apart. Returns a map name => [rx_bps, tx_bps] plus host totals.
     */
    public function rates(float $intervalSec = 1.2): array
    {
        $a = $this->interfaces();
        usleep((int) ($intervalSec * 1_000_000));
        $b = $this->interfaces();
        $byName = [];
        foreach ($b as $row) {
            $byName[$row['name']] = $row;
        }
        $rates = [];
        $totRx = 0;
        $totTx = 0;
        foreach ($a as $row) {
            $cur = $byName[$row['name']] ?? $row;
            $dt = max(0.001, $intervalSec);
            $rx = max(0, $cur['rx_bytes'] - $row['rx_bytes']) / $dt;
            $tx = max(0, $cur['tx_bytes'] - $row['tx_bytes']) / $dt;
            $rates[$row['name']] = ['rx_bps' => $rx, 'tx_bps' => $tx];
            $totRx += $cur['rx_bytes'];
            $totTx += $cur['tx_bytes'];
        }
        return ['rates' => $rates, 'total_rx_bytes' => $totRx, 'total_tx_bytes' => $totTx];
    }

    /** Per-account current-month usage rows (bytes desc, top $limitRows). */
    public function accountUsage(string $month = null, int $limitRows = 25): array
    {
        $month = $month ?: date('Y-m');
        $rows = app('db')->select(
            'SELECT u.`bytes`, u.`suspended`, a.`username`, p.`bandwidth_mb`
             FROM `bandwidth_usage` u
             JOIN `accounts` a ON a.`id` = u.`account_id`
             JOIN `packages` p ON p.`id` = a.`package_id`
             WHERE u.`month` = ? AND u.`bytes` > 0
             ORDER BY u.`bytes` DESC LIMIT ' . max(1, min(200, $limitRows)),
            [$month]
        );
        $out = [];
        foreach ($rows as $row) {
            $limitMb = (int) ($row['bandwidth_mb'] ?? 0);
            $bytes = (int) $row['bytes'];
            $out[] = [
                'username' => $row['username'],
                'bytes' => $bytes,
                'mb' => round($bytes / 1048576, 1),
                'limit' => $limitMb,
                'limit_bytes' => $limitMb * 1048576,
                'pct' => $limitMb > 0 ? round($bytes / ($limitMb * 1048576) * 100) : null,
                'suspended' => !empty($row['suspended']),
            ];
        }
        return $out;
    }

    /** Sum of all account usage for a month (bytes). */
    public function monthlyTotal(string $month = null): int
    {
        $month = $month ?: date('Y-m');
        return (int) app('db')->scalar(
            'SELECT COALESCE(SUM(`bytes`), 0) FROM `bandwidth_usage` WHERE `month` = ?',
            [$month]
        );
    }

    /** Human formatting for the view (delegate to SystemInfo). */
    public static function fmt(int $bytes): string
    {
        return SystemInfo::formatBytes($bytes);
    }
}
