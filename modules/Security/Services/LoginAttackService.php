<?php
declare(strict_types=1);
namespace Modules\Security\Services;

/**
 * Failed panel-login visibility + automatic IP blocking.
 *
 * Every failed password attempt is audited as `auth.failed` (by Auth) with
 * the request IP, so failures are visible in the existing Audit Log. This
 * service aggregates those rows: the Security overview lists top offenders,
 * and recordFailure() firewall-blocks an IP once it crosses the configured
 * threshold inside the window (called best-effort from LoginController, so
 * a block failure can never break the login flow).
 *
 * Loopback and the panel's own public IP are never blocked — blocking those
 * would lock out local services (webmail health checks, mail policy) or the
 * administrator. Service-level brute force (SSH/mail/FTP) is fail2ban's job
 * (deploy/cwhpanel-fail2ban.conf); this service covers panel HTTP logins.
 */
class LoginAttackService
{
    public const FAILED_ACTION = 'auth.failed';
    public const BLOCK_SERVICE = 'panel-login';

    /** @return array{threshold:int,window:int} */
    public function autoBlockConfig(): array
    {
        return [
            'threshold' => (int) config('auth.login.auto_block_threshold', 15),
            'window' => (int) config('auth.login.auto_block_window', 900),
        ];
    }

    /**
     * Record a failed login from $ip (already audited by Auth) and block the
     * IP when it crosses the threshold. Returns 'blocked', 'already-blocked',
     * 'skipped' (protected/unparseable IP or below threshold), never throws.
     */
    public function recordFailure(string $ip, string $email): string
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || self::isProtectedIp($ip)) {
            return 'skipped';
        }
        try {
            $cfg = $this->autoBlockConfig();
            $since = date('Y-m-d H:i:s', time() - $cfg['window']);
            $count = (int) app('db')->scalar(
                'SELECT COUNT(*) FROM `audit_logs` WHERE `action` = ? AND `ip_address` = ? AND `created_at` >= ?',
                [self::FAILED_ACTION, $ip, $since]
            );
            if ($count < $cfg['threshold']) {
                return 'skipped';
            }
            $existing = app('db')->selectOne('SELECT `id` FROM `firewall_rules` WHERE `ip_address` = ?', [$ip]);
            if ($existing) {
                return 'already-blocked';
            }
            $reason = 'auto-block: ' . $count . ' failed panel logins (last: ' . mb_substr($email, 0, 80) . ')';
            app('db')->statement(
                'INSERT INTO `firewall_rules` (`ip_address`,`action`,`reason`,`created_at`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `reason`=VALUES(`reason`)',
                [$ip, 'block', $reason, date('Y-m-d H:i:s')]
            );
            app('db')->insert('firewall_attacks', [
                'source_ip' => $ip, 'service' => self::BLOCK_SERVICE, 'action' => 'block',
                'reason' => $reason, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            try {
                app('provisioning')->execute('firewall.ip.block', ['ip' => $ip]);
            } catch (\Throwable $e) {
                app('logger')->warning('Login auto-block enforce failed for ' . $ip . ': ' . $e->getMessage());
            }
            audit('firewall.auto.blocked', 'firewall_rule', null, ['ip' => $ip, 'failures' => $count]);
            return 'blocked';
        } catch (\Throwable $e) {
            app('logger')->warning('Login auto-block check failed: ' . $e->getMessage());
            return 'skipped';
        }
    }

    /** Top offending IPs in the trailing window (for the Security overview). */
    public function topOffenders(int $limit = 10, int $windowSeconds = 86400): array
    {
        try {
            $limit = max(1, min(50, $limit));
            $since = date('Y-m-d H:i:s', time() - $windowSeconds);
            return app('db')->select(
                'SELECT `ip_address`, COUNT(*) AS `failures`, MAX(`created_at`) AS `last_at`'
                . ' FROM `audit_logs` WHERE `action` = ? AND `created_at` >= ?'
                . ' GROUP BY `ip_address` ORDER BY `failures` DESC LIMIT ' . $limit,
                [self::FAILED_ACTION, $since]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Most recent raw failed-login rows (for the Security overview). */
    public function recentFailures(int $limit = 20): array
    {
        try {
            $limit = max(1, min(50, $limit));
            return app('db')->select(
                'SELECT `ip_address`, `metadata`, `created_at` FROM `audit_logs`'
                . ' WHERE `action` = ? ORDER BY `id` DESC LIMIT ' . $limit,
                [self::FAILED_ACTION]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** IPs that must never be auto-blocked (local services + self). */
    public static function isProtectedIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || str_starts_with($ip, '127.') || $ip === '::1') {
            return true;
        }
        try {
            $self = trim((string) env('PANEL_PUBLIC_IP', ''));
        } catch (\Throwable $e) {
            $self = '';
        }
        return $self !== '' && $ip === $self;
    }
}
