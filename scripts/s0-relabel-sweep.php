#!/usr/bin/env php
<?php
/**
 * Weekly MCS `s0`-fallback sweep: inventory every active account's home for
 * files whose SELinux level has NO MCS category and — when found — apply the
 * account's assigned category (selinux.s0.fix). Closes the documented residual
 * where MCS does not isolate such files across tenants.
 *
 * Run from the repo root (as root, provisioning enabled):
 *   php scripts/s0-relabel-sweep.php
 *
 * Safe to run repeatedly: fix is idempotent and inventory is read-only.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$db = app('db');
$ops = app('provisioning');

$accounts = $db->select(
    "SELECT `id`, `username`, `mcs_category`, `status` FROM `accounts` WHERE `mcs_category` > 0"
);
$scanned = 0;
$fixed = 0;
$failed = 0;

foreach ($accounts as $a) {
    $username = (string) $a['username'];
    $cat = (int) $a['mcs_category'];
    try {
        $inv = $ops->execute('selinux.s0.inventory', ['account' => $username]);
        $out = (string) ($inv['output'] ?? '');
        if (preg_match('/uncategorized=(\d+)/', $out, $m) && (int) $m[1] > 0) {
            $ops->execute('selinux.s0.fix', [
                'account' => $username,
                'category' => 'c' . $cat,
            ]);
            $fixed++;
            app('logger')->info('s0 sweep: relabeled account ' . $username . ' (' . $m[1] . ' files)');
            echo "fixed {$username}: " . $m[1] . " uncategorized files\n";
        }
        $scanned++;
    } catch (\Throwable $e) {
        $failed++;
        app('logger')->warning('s0 sweep failed for ' . $username . ': ' . $e->getMessage());
        echo "failed {$username}: " . $e->getMessage() . "\n";
    }
}

echo "s0 sweep done: scanned={$scanned} fixed={$fixed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
