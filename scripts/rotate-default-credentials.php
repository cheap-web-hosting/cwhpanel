#!/usr/bin/env php
<?php
/**
 * Rotate the PUBLISHED seed passwords if (and only if) they are still live.
 *
 * Historically the installer seeded `admin@example.com` /
 * `Admin-Change-Me-123!` and `customer@example.com` / `Customer-Change-Me-123!`,
 * both documented in the public repository. A fresh install now seeds a RANDOM
 * admin password (written to `.env`), so this script is a no-op there.
 *
 * It exists for legacy boxes that still authenticate with a published default:
 * it detects that by verifying the default against the stored hash, generates a
 * strong replacement, updates users.password, and writes the new value to the
 * panel `.env`. A password the operator chose is NEVER overwritten.
 *
 * Run from the repo root (as root):
 *   php scripts/rotate-default-credentials.php
 *
 * Passwords are written to .env only (never printed, never committed).
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$db = app('db');

$targets = [
    [
        'label' => 'administrator',
        'env' => 'ADMIN_PASSWORD',
        'email' => (string) env('ADMIN_EMAIL', 'admin@example.com'),
        'defaults' => ['Admin-Change-Me-123!'],
        'admin' => true,
    ],
    [
        'label' => 'demo customer',
        'env' => 'CUSTOMER_PASSWORD',
        'email' => (string) env('CUSTOMER_EMAIL', 'customer@example.com'),
        'defaults' => ['Customer-Change-Me-123!'],
        'admin' => false,
    ],
];

$envFiles = ['/opt/cwhpanel/.env', BASE_PATH . '/.env'];
$changed = [];

foreach ($targets as $t) {
    $row = $db->selectOne('SELECT `id`, `password` FROM `users` WHERE `email` = ?', [$t['email']]);
    // A production install may use a different ADMIN_EMAIL, or have no demo
    // customer at all; fall back to the first admin for the admin target.
    if ($row === null && $t['admin']) {
        $row = $db->selectOne('SELECT `id`, `password` FROM `users` WHERE `is_admin` = 1 ORDER BY `id` ASC LIMIT 1');
    }
    if ($row === null) {
        fwrite(STDERR, "skip: {$t['label']} [{$t['email']}] not found\n");
        continue;
    }

    $published = false;
    foreach ($t['defaults'] as $default) {
        if (password_verify($default, (string) $row['password'])) {
            $published = true;
            break;
        }
    }
    if (!$published) {
        echo "skip: {$t['label']} does not use a published default\n";
        continue;
    }

    $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', 'Aa'), '=');
    $db->update('users', [
        'password' => hash_password($password),
        'updated_at' => date('Y-m-d H:i:s'),
    ], '`id` = ?', [(int) $row['id']]);

    foreach (array_unique($envFiles) as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }
        $contents = (string) file_get_contents($file);
        if (preg_match('/^' . preg_quote($t['env'], '/') . '=.*$/m', $contents)) {
            $contents = preg_replace(
                '/^' . preg_quote($t['env'], '/') . '=.*$/m',
                $t['env'] . '=' . $password,
                $contents
            );
        } else {
            $contents = rtrim($contents, "\n") . "\n" . $t['env'] . '=' . $password . "\n";
        }
        $mode = fileperms($file) & 0777;
        file_put_contents($file, $contents, LOCK_EX);
        chmod($file, $mode ?: 0640);
        // /opt/cwhpanel/.env must stay readable by the panel pool.
        if ($file === '/opt/cwhpanel/.env') {
            @chown($file, 'root');
            @chgrp($file, 'cwhpanel-panel');
        }
    }

    $changed[] = $t['email'];
}

if ($changed === []) {
    echo "No published default credentials in use; nothing to rotate.\n";
    exit(0);
}

echo "Rotated published seed credentials for: " . implode(', ', $changed) . "\n";
echo "New values are in /opt/cwhpanel/.env (ADMIN_PASSWORD / CUSTOMER_PASSWORD).\n";
echo "Reload the panel if it caches config: systemctl reload-or-restart php-fpm\n";
