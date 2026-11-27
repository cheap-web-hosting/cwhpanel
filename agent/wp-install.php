#!/usr/bin/php
<?php
/**
 * cwhpanel WordPress unattended installer helper.
 *
 * Runs as the hosting account user (via runuser from the privileged agent):
 *   php wp-install.php /home/<user>[/<subdir>]
 *
 * Data travels via environment (no shell interpolation anywhere):
 *   WP_TITLE  site title (1-200 chars, no NUL/newlines)
 *   WP_USER   admin username ([A-Za-z0-9_.-], 1-32)
 *   WP_PASS   admin password (8-256 chars, no NUL/newlines)
 *   WP_EMAIL  admin email (valid address, <=254)
 *   WP_URL    public site URL (http(s)://host[/path], <=255)
 *
 * Behavior:
 *   - chdir into the install tree, loads wp-load.php + upgrade.php
 *   - idempotent: when wp_users already has rows, prints ALREADY and exits 0
 *   - otherwise calls wp_install() with the provided values, then pins
 *     siteurl/home to WP_URL so CLI installs never guess a wrong host
 *     (localhost/loopback) the way wp_guess_url() would.
 *
 * Exit codes: 0 ok (INSTALLED or ALREADY), 1 usage/validation, 2 WP failure.
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
if (!is_dir($dest)) {
    fail(1, "install path is not a directory: {$dest}");
}
$load = rtrim($dest, '/') . '/wp-load.php';
$upgrade = rtrim($dest, '/') . '/wp-admin/includes/upgrade.php';
if (!is_file($load) || !is_file($upgrade)) {
    fail(1, 'not a WordPress tree (wp-load.php / upgrade.php missing)');
}

$title = (string) (getenv('WP_TITLE') ?: '');
$user = (string) (getenv('WP_USER') ?: '');
$pass = (string) (getenv('WP_PASS') ?: '');
$email = (string) (getenv('WP_EMAIL') ?: '');
$url = (string) (getenv('WP_URL') ?: '');

$title = trim($title);
$user = trim($user);
$email = trim($email);
$url = trim($url);

if ($title === '' || strlen($title) > 200
    || str_contains($title, "\0") || str_contains($title, "\n") || str_contains($title, "\r")
) {
    fail(1, 'invalid site title');
}
if (preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) !== 1) {
    fail(1, 'invalid admin username');
}
if (strlen($pass) < 8 || strlen($pass) > 256
    || str_contains($pass, "\0") || str_contains($pass, "\n") || str_contains($pass, "\r")
) {
    fail(1, 'invalid admin password (need 8-256 chars)');
}
if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fail(1, 'invalid admin email');
}
if (strlen($url) > 255
    || preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._/-]*)?$#', $url) !== 1
    || str_contains($url, '..')
) {
    fail(1, 'invalid site URL');
}

if (!chdir(rtrim($dest, '/'))) {
    fail(1, 'cannot chdir into install tree');
}

// Silence WP's own output; errors surface via the exit code + STDERR.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

try {
    if (!defined('WP_INSTALLING')) {
        define('WP_INSTALLING', true);
    }
    require $load;
    require_once $upgrade;
} catch (Throwable $e) {
    fail(2, 'wordpress bootstrap failed: ' . $e->getMessage());
}

global $wpdb;
try {
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->users}`");
} catch (Throwable $e) {
    fail(2, 'database not ready: ' . $e->getMessage());
}
if ($count > 0) {
    echo "ALREADY\n";
    exit(0);
}

try {
    $result = wp_install($title, $user, $email, true, '', $pass);
} catch (Throwable $e) {
    fail(2, 'wp_install failed: ' . $e->getMessage());
}
if ($result instanceof WP_Error) {
    fail(2, 'wp_install failed: ' . $result->get_error_message());
}

// Pin the public URL: under CLI wp_guess_url() would otherwise record
// whatever host the worker sees instead of the real domain. Direct $wpdb
// writes + re-read verification: option-cache subtleties must never leave
// home/siteurl guessing again.
foreach (['siteurl', 'home'] as $opt) {
    $updated = $wpdb->update($wpdb->options, ['option_value' => $url], ['option_name' => $opt]);
    if ($updated === false) {
        fail(2, "could not pin option {$opt}: " . ($wpdb->last_error ?: 'unknown db error'));
    }
}
foreach (['siteurl', 'home'] as $opt) {
    $actual = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $opt));
    if ($actual !== $url) {
        fail(2, "option {$opt} did not stick (got '{$actual}')");
    }
}
wp_cache_flush();

echo "INSTALLED user_id=" . (int) ($result['user_id'] ?? 0) . "\n";
exit(0);
