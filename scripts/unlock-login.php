<?php

declare(strict_types=1);

/**
 * Clear login throttle rows for one address, one IP, or everything.
 *
 * The login limiter is a shared table (`login_attempts`). A burst of failures
 * — or an attacker deliberately pinning an account — refuses that account
 * before the password is even checked, and the only automatic way out is the
 * decay window. This gives an operator an immediate, auditable unlock.
 *
 * Usage:
 *   php scripts/unlock-login.php admin@example.com
 *   php scripts/unlock-login.php 203.0.113.9
 *   php scripts/unlock-login.php all
 */

require __DIR__ . '/_bootstrap.php';

$app = new App\Foundation\Application(BASE_PATH);
$app->bootstrap();

$arg = trim((string) ($argv[1] ?? ''));
if ($arg === '') {
    fwrite(STDERR, "usage: php scripts/unlock-login.php <email|ip|all>\n");
    exit(2);
}

$db = app('db');
$n = 0;

if (strcasecmp($arg, 'all') === 0) {
    $n = $db->delete('login_attempts', '1 = 1');
} elseif (filter_var($arg, FILTER_VALIDATE_IP) !== false) {
    $n = $db->delete(
        'login_attempts',
        '`key` = ? OR `key` = ? OR `key` LIKE ?',
        ['login-ip:' . $arg, 'login-global-ip:' . $arg, 'login:%|' . $arg]
    );
} else {
    $n = $db->delete('login_attempts', '`key` LIKE ?', ['login:' . mb_strtolower($arg) . '|%']);
}

audit('auth.throttle.cleared', 'login_attempts', null, ['target' => $arg, 'rows' => (int) $n]);
echo "cleared {$n} throttle row(s) for {$arg}\n";
