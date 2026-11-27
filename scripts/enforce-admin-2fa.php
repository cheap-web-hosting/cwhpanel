#!/usr/bin/env php
<?php
/**
 * Enforce admin 2FA setting safely: sets `auth.require_admin_2fa=1` ONLY when
 * every active admin already has a TOTP secret enrolled (otherwise enabling it
 * would lock out an admin that never set one up). Best for fresh turn-ups or
 * post-enrollment runs.
 *
 *   php scripts/enforce-admin-2fa.php
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$db = app('db');

$admins = $db->select("SELECT `id`, `email`, `two_factor_secret`, `status` FROM `users` WHERE `is_admin` = 1");
$active = array_filter($admins, fn ($u) => ($u['status'] ?? 'active') === 'active');
$unenrolled = array_filter($active, fn ($u) => empty($u['two_factor_secret']));

if ($unenrolled !== []) {
    $emails = implode(', ', array_column($unenrolled, 'email'));
    fwrite(STDERR, "auth.require_admin_2fa left OFF — admin(s) without TOTP: {$emails}\n");
    exit(2);
}

$row = $db->selectOne("SELECT `value` FROM `settings` WHERE `key` = 'auth.require_admin_2fa' LIMIT 1");
if ($row === null) {
    // settings' primary column is `key` (CreateSettingsTable); the old writer
    // used `name`, so this INSERT always threw and enforcement was never
    // enabled by the documented path.
    $db->insert('settings', ['key' => 'auth.require_admin_2fa', 'value' => '1']);
} else {
    $db->update('settings', ['value' => '1'], "`key` = 'auth.require_admin_2fa'");
}
echo "auth.require_admin_2fa=1 enforced (all active admins have TOTP)\n";
