<?php

declare(strict_types=1);

namespace Modules\Packages\Services;

/**
 * LimitService — package-tied creation-count enforcement.
 *
 * Package columns like max_domains used to be display-only: nothing stopped
 * over-limit creation on any path. Every customer AND admin store path calls
 * require() before provisioning or inserting, and InstallerService covers
 * catalog app-type installs that mint applications rows outside the
 * Applications controllers.
 *
 * Semantics: 0 = unlimited (matches bandwidth/mysql caps elsewhere).
 * Domains count main/addon/parked rows; subdomains have their own counter.
 * Cron counts all rows (a paused job still occupies its slot).
 */
class LimitService
{
    /** type => [package column, human label] */
    public const TYPES = [
        'domains' => ['max_domains', 'domains'],
        'subdomains' => ['max_subdomains', 'subdomains'],
        'databases' => ['max_databases', 'databases'],
        'mailboxes' => ['max_email_accounts', 'email accounts'],
        'ftp' => ['max_ftp_accounts', 'FTP accounts'],
        'cron' => ['max_cron_jobs', 'cron jobs'],
        'node' => ['max_nodejs_apps', 'Node.js apps'],
        'python' => ['max_python_apps', 'Python apps'],
    ];

    /**
     * Absolute per-account ceilings for resources that have no package column
     * (abuse guard): a single account must not be able to mint unbounded
     * forwarders/lists/certs/backups. 0 would mean unlimited; these are fixed.
     */
    public const HARD_CAPS = [
        'forwarders' => 1000,
        'mailing_lists' => 500,
        'certificates' => 500,
        'backups' => 500,
    ];

    /** Throw when an account is at its hard cap for a resource type. */
    public static function requireHardCap(int $accountId, string $type): void
    {
        $cap = self::HARD_CAPS[$type] ?? 0;
        if ($cap <= 0 || $accountId <= 0) {
            return;
        }
        try {
            $n = (int) app('db')->scalar("SELECT COUNT(*) FROM `{$type}` WHERE `account_id` = ?", [$accountId]);
        } catch (\Throwable $e) {
            return;
        }
        if ($n >= $cap) {
            throw new \RuntimeException('Account limit reached for ' . str_replace('_', ' ', $type) . " (max {$cap}).");
        }
    }

    /**
     * Package ceiling for an account+type. NULL = unlimited (no cap), 0 =
     * nothing allowed. Unknown type or a missing account/package row also
     * yields NULL (fail-open: legacy rows must never block provisioning).
     */
    public static function limitFor(int $accountId, string $type): ?int
    {
        if (!isset(self::TYPES[$type])) {
            return null;
        }
        try {
            $row = app('db')->selectOne(
                'SELECT p.`' . self::TYPES[$type][0] . '` AS `limit` FROM `accounts` a
                 LEFT JOIN `packages` p ON p.`id` = a.`package_id`
                 WHERE a.`id` = ?',
                [$accountId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
        if ($row === null || $row['limit'] === null || !isset($row['limit'])) {
            return null;
        }
        return max(0, (int) $row['limit']);
    }

    /** Current usage count for an account+type. */
    public static function countFor(int $accountId, string $type): int
    {
        $db = app('db');
        try {
            return match ($type) {
                'domains' => (int) $db->scalar(
                    "SELECT COUNT(*) FROM `domains` WHERE `account_id` = ? AND `type` != 'subdomain'", [$accountId]),
                'subdomains' => (int) $db->scalar(
                    "SELECT COUNT(*) FROM `domains` WHERE `account_id` = ? AND `type` = 'subdomain'", [$accountId]),
                'databases' => (int) $db->scalar(
                    'SELECT COUNT(*) FROM `hosting_databases` WHERE `account_id` = ?', [$accountId]),
                'mailboxes' => (int) $db->scalar(
                    'SELECT COUNT(*) FROM `mailboxes` WHERE `account_id` = ?', [$accountId]),
                'ftp' => (int) $db->scalar(
                    'SELECT COUNT(*) FROM `ftp_accounts` WHERE `account_id` = ?', [$accountId]),
                'cron' => (int) $db->scalar(
                    'SELECT COUNT(*) FROM `cron_jobs` WHERE `account_id` = ?', [$accountId]),
                'node' => (int) $db->scalar(
                    "SELECT COUNT(*) FROM `applications` WHERE `account_id` = ? AND `runtime` = 'node'", [$accountId]),
                'python' => (int) $db->scalar(
                    "SELECT COUNT(*) FROM `applications` WHERE `account_id` = ? AND `runtime` = 'python'", [$accountId]),
                default => 0,
            };
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * True when the package explicitly disables a runtime entirely
     * (max_nodejs_apps / max_python_apps = 0). Used to stop existing apps and
     * to refuse restarts, not just new app creation.
     */
    public static function runtimeDisabled(int $accountId, string $runtime): bool
    {
        if (!in_array($runtime, ['node', 'python'], true)) {
            return false;
        }

        return self::limitFor($accountId, $runtime) === 0;
    }

    /**
     * Throw when the account is at (or above) its ceiling for the type.
     * NULL = unlimited, so unknown types and legacy packages stay fail-open.
     * 0 = nothing allowed: the operation is refused even with zero used.
     */
    public static function require(int $accountId, string $type): void
    {
        if (!isset(self::TYPES[$type])) {
            return;
        }
        $limit = self::limitFor($accountId, $type);
        if ($limit === null) {
            return;
        }
        $label = self::TYPES[$type][1];
        if ($limit === 0) {
            throw new \RuntimeException('This package does not allow any ' . $label . '.');
        }
        $used = self::countFor($accountId, $type);
        if ($used >= $limit) {
            throw new \RuntimeException("Package limit reached: {$used}/{$limit} {$label} used.");
        }
    }
}
