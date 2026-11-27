#!/usr/bin/php
<?php
/**
 * cwhpanel Drupal unattended installer helper.
 *
 * Runs as the hosting account user (via runuser from the privileged agent):
 *   php drupal-install.php /home/<user>[/<subdir>]
 *
 * Data travels via environment (no shell interpolation anywhere):
 *   DR_DB_HOST, DR_DB_NAME, DR_DB_USER, DR_DB_PASS
 *   DR_ADMIN_USER, DR_ADMIN_PASS, DR_ADMIN_EMAIL, DR_TITLE
 *
 * Drives Drupal's own installer with the MySQL driver (the stock `dr
 * install` command always installs to SQLite and hardcodes the admin as
 * 'admin'/'admin@example.com', so it cannot use a panel-managed database).
 *
 * Idempotent: a tree that already has a populated settings.php signals
 * ALREADY and exits 0.
 *
 * Exit codes: 0 ok (INSTALLED or ALREADY), 1 usage/validation, 2 failure.
 */

declare(strict_types=1);

function fail(int $code, string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit($code);
}

$dest = $argv[1] ?? '';
if ($dest === '' || str_contains($dest, "\0") || str_contains($dest, "\n")) {
    fail(1, 'missing install path');
}
$dest = rtrim($dest, '/');
if (!is_dir($dest) || !is_file($dest . '/core/includes/install.core.inc')) {
    fail(1, 'not a Drupal tree');
}

$dbHost = trim((string) (getenv('DR_DB_HOST') ?: ''));
$dbName = trim((string) (getenv('DR_DB_NAME') ?: ''));
$dbUser = trim((string) (getenv('DR_DB_USER') ?: ''));
$dbPass = (string) (getenv('DR_DB_PASS') ?: '');
$adminUser = trim((string) (getenv('DR_ADMIN_USER') ?: ''));
$adminPass = (string) (getenv('DR_ADMIN_PASS') ?: '');
$adminEmail = trim((string) (getenv('DR_ADMIN_EMAIL') ?: ''));
$title = trim((string) (getenv('DR_TITLE') ?: ''));

if (!in_array($dbHost, ['127.0.0.1', 'localhost'], true)) {
    fail(1, 'invalid database host');
}
if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName) !== 1) {
    fail(1, 'invalid database name');
}
if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $dbUser) !== 1) {
    fail(1, 'invalid database user');
}
if ($dbPass === '' || strlen($dbPass) > 256) {
    fail(1, 'invalid database password');
}
if (preg_match('/^[A-Za-z0-9_.@-]{1,60}$/', $adminUser) !== 1) {
    fail(1, 'invalid admin username');
}
if (strlen($adminPass) < 8 || strlen($adminPass) > 256) {
    fail(1, 'invalid admin password (need 8-256 chars)');
}
if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
    fail(1, 'invalid admin email');
}
if ($title === '' || strlen($title) > 200) {
    $title = 'Drupal';
}

$settings = $dest . '/sites/default/settings.php';
if (is_file($settings)) {
    // The installer writes $databases['default']['default']; the stock
    // default.settings.php only has an empty $databases = [].
    $existing = (string) file_get_contents($settings);
    if (str_contains($existing, "\$databases['default']['default']")) {
        echo "ALREADY\n";
        exit(0);
    }
}

if (!defined('DRUPAL_ROOT')) {
    define('DRUPAL_ROOT', $dest);
}
chdir($dest);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

if (!is_dir($dest . '/sites/default/files')) {
    @mkdir($dest . '/sites/default/files', 0755, true);
}
// Drupal's installer refuses to run unless settings.php exists (dr copies
// default.settings.php first; we mirror that).
if (!is_file($settings)) {
    if (!@copy($dest . '/sites/default/default.settings.php', $settings)) {
        fail(2, 'could not create sites/default/settings.php');
    }
}

try {
    $classLoader = require $dest . '/autoload.php';
    require_once $dest . '/core/includes/install.core.inc';
} catch (Throwable $e) {
    fail(2, 'drupal bootstrap failed: ' . $e->getMessage());
}

$mysqlNs = 'Drupal\\mysql\\Driver\\Database\\mysql';
$parameters = [
    'interactive' => false,
    // Drupal expects a path RELATIVE to the install root here.
    'site_path' => 'sites/default',
    'parameters' => ['profile' => 'standard', 'langcode' => 'en'],
    'forms' => [
        'install_settings_form' => [
            'driver' => $mysqlNs,
            $mysqlNs => [
                'database' => $dbName,
                'username' => $dbUser,
                'password' => $dbPass,
                'host' => $dbHost,
                'port' => '3306',
                'prefix' => '',
            ],
        ],
        'install_configure_form' => [
            'site_name' => $title,
            'site_mail' => $adminEmail,
            'account' => [
                'name' => $adminUser,
                'mail' => $adminEmail,
                'pass' => ['pass1' => $adminPass, 'pass2' => $adminPass],
            ],
            'enable_update_status_module' => true,
            'enable_update_status_emails' => null,
        ],
    ],
];

try {
    install_drupal($classLoader, $parameters);
} catch (Throwable $e) {
    fail(2, 'drupal install failed: ' . $e->getMessage());
}

echo "INSTALLED user={$adminUser}\n";
exit(0);
