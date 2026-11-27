#!/usr/bin/env php
<?php
/**
 * Reset a panel login password — root recovery (the `passwd` equivalent).
 *
 * Root on the box can always recover panel access this way, without coupling
 * the web admin secret to the system root password. Sharing one secret across
 * the web login and root/SSH multiplies both attack surfaces (a web-login
 * brute force becomes a root brute force), breaks audit separation, and turns
 * every panel-password rotation into a root-password rotation.
 *
 *   php scripts/reset-admin.php --email=admin@example.com [--password=...]
 *
 * Without --password a strong random one is generated and printed ONCE —
 * store it immediately. Must run as root (it uses root-only DB credentials).
 * Matching ADMIN_PASSWORD/CUSTOMER_PASSWORD entries in the repo + live .env
 * files are synced best-effort so env, docs and database agree.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "ERROR: must run as root.\n");
    exit(1);
}

$opts = getopt('', ['email:', 'password::']);
$email = strtolower(trim((string) ($opts['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/reset-admin.php --email=admin@example.com [--password=...]\n");
    exit(2);
}
$given = (string) ($opts['password'] ?? '');
if ($given !== '' && strlen($given) < 12) {
    fwrite(STDERR, "ERROR: password must be at least 12 characters.\n");
    exit(2);
}
$password = $given !== '' ? $given : bin2hex(random_bytes(12));

$app = new Application(BASE_PATH);
$app->bootstrap();

$db = app('db');
$user = $db->selectOne('SELECT `id`, `email`, `is_admin` FROM `users` WHERE `email` = ?', [$email]);
if ($user === null) {
    fwrite(STDERR, "ERROR: no panel user {$email}.\n");
    exit(1);
}

$db->update('users', ['password' => hash_password($password), 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $user['id']]);
audit('user.password.reset', 'user', (int) $user['id'], ['email' => $email, 'by' => 'root-cli']);

// Keep the .env credential entries consistent (seed skips existing users, so
// these are documentation — the database row is what gates login).
foreach ([BASE_PATH . '/.env', '/opt/cwhpanel/.env'] as $envFile) {
    try {
        if (!is_file($envFile) || !is_readable($envFile)) {
            continue;
        }
        $env = file_get_contents($envFile);
        if ($env === false) {
            continue;
        }
        $key = null;
        if (preg_match('/^ADMIN_EMAIL=' . preg_quote($email, '/') . '$/m', $env)) {
            $key = 'ADMIN_PASSWORD';
        } elseif (preg_match('/^CUSTOMER_EMAIL=' . preg_quote($email, '/') . '$/m', $env)) {
            $key = 'CUSTOMER_PASSWORD';
        }
        if ($key === null) {
            continue;
        }
        if (preg_match('/^' . $key . '=.*$/m', $env)) {
            $env = preg_replace('/^' . $key . '=.*$/m', $key . '=' . $password, $env);
        } else {
            $env = rtrim($env) . "\n" . $key . '=' . $password . "\n";
        }
        @file_put_contents($envFile, $env, LOCK_EX);
    } catch (\Throwable $e) {
    }
}

echo "Password reset for {$email} (" . (!empty($user['is_admin']) ? 'admin' : 'customer') . ").\n";
if ($given === '') {
    echo "New password (printed once — store it now): {$password}\n";
} else {
    echo "Password set from --password.\n";
}
