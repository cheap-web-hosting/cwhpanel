#!/usr/bin/env php
<?php
/**
 * Monthly bandwidth enforcement sweep (daily via cron).
 *
 * Totals each active account's served bytes for the current month and applies
 * the package's `bandwidth_mb`: warning mail at 80% / 95%, site suspension at
 * 100%, and automatic recovery when usage drops back under the cap (new month
 * or upgraded package).
 *
 * The free edition has no Watchdog module, so this replaces the old
 * `watch.bandwidth` check with a plain cron entry.
 *
 *   php scripts/bandwidth-sweep.php [--dry-run]
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;
use Modules\Packages\Services\BandwidthService;

$app = new Application(BASE_PATH);
$app->bootstrap();

$dry = in_array('--dry-run', $argv, true);

if ($dry) {
    echo "bandwidth-sweep: dry run (no changes)\n";
    $accounts = app('db')->select(
        'SELECT a.`id`, a.`username`, p.`bandwidth_mb`
           FROM `accounts` a JOIN `packages` p ON p.`id` = a.`package_id`
          WHERE a.`status` = ?',
        ['active']
    );
    foreach ($accounts as $row) {
        printf("  %-20s limit=%s MB\n", $row['username'], (int) $row['bandwidth_mb']);
    }
    exit(0);
}

$stats = app(BandwidthService::class)->sweep();
printf(
    "bandwidth-sweep: accounts=%d suspended=%d unsuspended=%d mailed=%d\n",
    $stats['accounts'] ?? 0,
    $stats['suspended'] ?? 0,
    $stats['unsuspended'] ?? 0,
    $stats['mailed'] ?? 0
);