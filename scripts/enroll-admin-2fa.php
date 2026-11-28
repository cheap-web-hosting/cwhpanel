#!/usr/bin/env php
<?php
/**
 * Enroll/rotate (or disable) TOTP two-factor authentication for a user.
 *
 *   php scripts/enroll-admin-2fa.php admin@example.com
 *   php scripts/enroll-admin-2fa.php admin@example.com --disable
 *
 * Prints the otpauth:// provisioning URI (scan with any authenticator app).
 * The seed is sealed at rest with the application key (audit #14). Once every
 * administrator is enrolled, enforce logins with:
 *   mysql cwhpanel -e "INSERT INTO settings (\`name\`,\`value\`) VALUES
 *     ('auth.require_admin_2fa','1') ON DUPLICATE KEY UPDATE \`value\`='1'"
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;
use Modules\Authentication\Services\Totp;

$app = new Application(BASE_PATH);
$app->bootstrap();

$email = $argv[1] ?? '';
if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/enroll-admin-2fa.php <email> [--disable]\n");
    exit(2);
}
$disable = in_array('--disable', $argv, true);

$row = app('db')->selectOne('SELECT * FROM `users` WHERE `email` = ? LIMIT 1', [$email]);
if ($row === null) {
    fwrite(STDERR, "No user with email {$email}\n");
    exit(1);
}

$totp = new Totp();

if ($disable) {
    app('db')->update('users', [
        'two_factor_enabled' => 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ], '`id` = ?', [(int) $row['id']]);
    echo "Disabled two-factor authentication for {$email}.\n";
    exit(0);
}

$secret = $totp->generateSecret();
app('db')->update('users', [
    'two_factor_secret' => \App\Foundation\Security\SecretBox::encrypt($secret),
    'two_factor_enabled' => 1,
    'updated_at' => date('Y-m-d H:i:s'),
], '`id` = ?', [(int) $row['id']]);

echo "Enrolled two-factor authentication for {$email}.\n";
echo "\nProvisioning URI:\n" . $totp->provisioningUri($secret, $email) . "\n\n";
echo "If 'auth.require_admin_2fa' is enabled, this user must now pass a TOTP\n";
echo "code at login (fail-closed until enrolled).\n";
exit(0);
