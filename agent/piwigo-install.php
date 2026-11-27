#!/usr/bin/php
<?php
/**
 * cwhpanel Piwigo unattended installer helper.
 *
 * Runs as the hosting account user (via runuser from the privileged agent):
 *   php piwigo-install.php /home/<user>[/<subdir>]
 *
 * Data travels via environment (no shell interpolation anywhere):
 *   PW_DB_HOST, PW_DB_NAME, PW_DB_USER, PW_DB_PASS, PW_PREFIX
 *   PW_ADMIN_USER, PW_ADMIN_PASS, PW_ADMIN_EMAIL, PW_DOMAIN
 *
 * Piwigo ships only a web installer (install.php) driven by a POST form;
 * this helper supplies the form values and runs it non-interactively, then
 * verifies local/config/database.inc.php. Idempotent (ALREADY).
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
if (!is_dir($dest) || !is_file($dest . '/install.php') || !is_dir($dest . '/include')) {
    fail(1, 'not a Piwigo tree');
}

$dbHost = trim((string) (getenv('PW_DB_HOST') ?: ''));
$dbName = trim((string) (getenv('PW_DB_NAME') ?: ''));
$dbUser = trim((string) (getenv('PW_DB_USER') ?: ''));
$dbPass = (string) (getenv('PW_DB_PASS') ?: '');
$prefix = trim((string) (getenv('PW_PREFIX') ?: 'piwigo_'));
$adminUser = trim((string) (getenv('PW_ADMIN_USER') ?: ''));
$adminPass = (string) (getenv('PW_ADMIN_PASS') ?: '');
$adminEmail = trim((string) (getenv('PW_ADMIN_EMAIL') ?: ''));
$domain = trim((string) (getenv('PW_DOMAIN') ?: ''));

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
if (preg_match('/^[a-zA-Z0-9_$]{1,20}$/', $prefix) !== 1 || preg_match('/^\d/', $prefix)) {
    fail(1, 'invalid table prefix');
}
if ($adminUser === '' || strlen($adminUser) > 100 || preg_match('/[\'"]/', $adminUser)) {
    fail(1, 'invalid admin username');
}
if (strlen($adminPass) < 8 || strlen($adminPass) > 256) {
    fail(1, 'invalid admin password (need 8-256 chars)');
}
if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
    fail(1, 'invalid admin email');
}
if ($domain === '' || strlen($domain) > 253) {
    $domain = 'localhost';
}

$configFile = $dest . '/local/config/database.inc.php';
if (is_file($configFile) && str_contains((string) file_get_contents($configFile), 'PHPWG_INSTALLED')) {
    echo "ALREADY\n";
    exit(0);
}

if (!is_dir($dest . '/local/config')) {
    @mkdir($dest . '/local/config', 0755, true);
}

chdir($dest);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = $domain;
$_SERVER['SERVER_NAME'] = $domain;
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/install.php';
$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'cwhpanel-installer';
$_GET = [];
$_POST = [
    'install' => '1',
    'prefix' => $prefix,
    'dbhost' => $dbHost,
    'dbuser' => $dbUser,
    'dbpasswd' => $dbPass,
    'dbname' => $dbName,
    'admin_name' => $adminUser,
    'admin_pass1' => $adminPass,
    'admin_pass2' => $adminPass,
    'admin_mail' => $adminEmail,
];

try {
    ob_start();
    require $dest . '/install.php';
    $output = (string) ob_get_clean();
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    fail(2, 'piwigo install failed: ' . $e->getMessage());
}

if (!is_file($configFile) || !str_contains((string) file_get_contents($configFile), 'PHPWG_INSTALLED')) {
    fail(2, 'piwigo install did not complete: ' . mb_substr(strip_tags($output), 0, 300));
}

echo "INSTALLED user={$adminUser}\n";
exit(0);
