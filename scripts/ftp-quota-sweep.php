#!/usr/bin/env php
<?php
/**
 * FTP quota sweep (daily via cron).
 *
 * vsftpd has no quota engine, so the panel enforces per-row quota_mb by
 * measuring each active FTP home (du, account-confined) and suspending
 * (login lock + status flag) rows over quota. Clearing is deliberately
 * manual: an admin unsuspend restores the login, and the next sweep
 * re-suspends if still over. Zero quota_mb rows are skipped (unlimited).
 *
 *   php scripts/ftp-quota-sweep.php
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$swept = 0;
$held = 0;
$unreadable = 0;

foreach (app('db')->select(
    "SELECT f.*, a.`username` AS account_username FROM `ftp_accounts` f
     JOIN `accounts` a ON a.`id` = f.`account_id`
     WHERE f.`status` = 'active' AND f.`quota_mb` > 0 ORDER BY f.`id`"
) as $row) {
    $swept++;
    $home = (string) ($row['home_dir'] ?? '');
    $base = '/home/' . $row['account_username'];
    if ($home !== $base && !str_starts_with($home, $base . '/')) {
        continue; // outside the tree: never touch, admin to fix.
    }
    $rel = ltrim(substr($home, strlen($base)), '/');
    $bytes = app(\Modules\Ftp\Services\FtpService::class)->homeUsage(
        (string) $row['account_username'], $rel === '' ? '.' : $rel
    );
    if ($bytes === null) {
        $unreadable++;
        continue;
    }
    if ($bytes > (int) $row['quota_mb'] * 1048576) {
        try {
            app(\Modules\Ftp\Services\FtpService::class)->lock((string) $row['username']);
        } catch (\Throwable $e) {
            app('logger')->warning('FTP quota lock failed for ' . $row['username'] . ': ' . $e->getMessage());
            continue;
        }
        app('db')->update('ftp_accounts', ['status' => 'suspended', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $row['id']]);
        audit('ftp.quota.suspended', 'ftp_account', (int) $row['id'], [
            'username' => $row['username'], 'bytes' => $bytes, 'quota_mb' => (int) $row['quota_mb'],
        ]);
        $held++;
    }
}

echo date('Y-m-d H:i:s') . " swept={$swept} held={$held} unreadable={$unreadable}\n";
