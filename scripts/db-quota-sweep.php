#!/usr/bin/env php
<?php
/**
 * Database storage quota sweep (every 15 min via cron).
 *
 * MariaDB has no per-schema quota, so the panel enforces the package's
 * `database_quota_mb` by measuring each account's databases
 * (information_schema) and revoking write privileges on the account's DBs
 * when over. Reads keep working and nothing is deleted: shrinking under the
 * quota (or a package upgrade) restores full grants on the next sweep.
 *
 *   php scripts/db-quota-sweep.php [--dry-run]
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;
use Modules\Core\Services\NotificationService;
use Modules\Databases\Services\DatabaseService;
use Modules\Databases\Services\MysqlGovernorService;

$app = new Application(BASE_PATH);
$app->bootstrap();

$dry = in_array('--dry-run', $argv, true);
$governor = app(MysqlGovernorService::class);
$db = app('db');

$rows = $db->select(
    "SELECT a.`id`, a.`username`, p.`database_quota_mb` FROM `accounts` a
       JOIN `packages` p ON p.`id` = a.`package_id`
      WHERE a.`status` = 'active' AND p.`database_quota_mb` > 0"
);

$over = 0;
$restored = 0;
$errors = 0;

$settingsGet = static function (string $key): ?string {
    $v = app('db')->scalar('SELECT `value` FROM `settings` WHERE `key` = ?', [$key]);

    return ($v === null || $v === false || $v === '') ? null : (string) $v;
};
$settingsSet = static function (string $key, ?string $value): void {
    if ($value === null) {
        app('db')->delete('settings', '`key` = ?', [$key]);
        return;
    }
    app('db')->statement(
        'INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
        [$key, $value, date('Y-m-d H:i:s')]
    );
};

$dbSvc = app(DatabaseService::class);
$notify = app(NotificationService::class);

foreach ($rows as $row) {
    $accountId = (int) $row['id'];
    $username = (string) $row['username'];
    $quotaMb = (int) $row['database_quota_mb'];
    $key = 'quota.db.over.' . $accountId;

    try {
        $used = $governor->accountDatabaseBytes($accountId);
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, "size probe failed for {$username}: " . $e->getMessage() . "\n");
        continue;
    }

    $isOver = $used >= $quotaMb * 1048576;
    $flagged = $settingsGet($key) !== null;
    if ($isOver === $flagged) {
        continue;
    }

    $usedMb = (int) round($used / 1048576);
    $dbs = $db->select('SELECT `name`, `username` FROM `hosting_databases` WHERE `account_id` = ?', [$accountId]);
    if ($dry) {
        echo '[dry-run] ' . $username . ': ' . $usedMb . '/' . $quotaMb . ' MB — would '
            . ($isOver ? 'revoke writes' : 'restore writes') . "\n";
        continue;
    }

    $failed = 0;
    foreach ($dbs as $d) {
        try {
            $dbSvc->setWriteAccess((string) $d['name'], (string) $d['username'], !$isOver);
        } catch (\Throwable $e) {
            $failed++;
            app('logger')->warning('DB quota grant toggle failed for ' . $d['name'] . ': ' . $e->getMessage());
        }
    }
    if ($dbs !== [] && $failed === count($dbs)) {
        $errors++;
        fwrite(STDERR, "could not toggle any database grants for {$username}\n");
        continue;
    }

    $settingsSet($key, $isOver ? date('c') : null);
    if ($isOver) {
        $over++;
        $notify->pushAccountOwner($username, 'quota', 'Database storage quota reached',
            "Your databases use {$usedMb} MB of a {$quotaMb} MB quota. Writes are blocked until usage drops.", '/panel/databases');
        $notify->pushAdmin('quota', 'Database quota exceeded: ' . $username,
            "{$usedMb}/{$quotaMb} MB — write grants revoked on {$username}'s databases.", '/admin/databases');
        audit('quota.database.exceeded', 'account', $accountId, ['used_mb' => $usedMb, 'quota_mb' => $quotaMb]);
    } else {
        $restored++;
        $notify->pushAccountOwner($username, 'quota', 'Database storage quota restored',
            "Your databases are back under the {$quotaMb} MB quota; writes are enabled again.", '/panel/databases');
        audit('quota.database.restored', 'account', $accountId, ['used_mb' => $usedMb]);
    }
}

echo "db-quota-sweep: over={$over} restored={$restored} errors={$errors}\n";