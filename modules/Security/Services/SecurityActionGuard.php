<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use App\Foundation\Database\Connection;

/**
 * Per-account cooldowns + single-flight for customer security actions
 * (audit #23).
 *
 * `acquire()` takes a MariaDB named lock for the account/action (so a second
 * request cannot run concurrently) and refuses when the previous run is inside
 * the cooldown window. The caller MUST call `release()` in a finally block.
 * With `$global` the action is additionally limited to one across all accounts
 * (antivirus scans: a host-wide concurrency budget of one).
 */
class SecurityActionGuard
{
    /** action => cooldown column (fixed, never user input). */
    private const COLUMNS = [
        'waf' => 'waf_last_at',
        'av' => 'av_last_at',
    ];

    private ?Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db;
    }

    /** null = acquired; a string = refusal reason for the customer. */
    public function acquire(int $accountId, string $action, int $cooldownSeconds, bool $global = false): ?string
    {
        $column = self::COLUMNS[$action] ?? null;
        if ($column === null || $accountId <= 0) {
            throw new \InvalidArgumentException('Invalid security action guard request.');
        }
        $db = $this->db();
        $lock = 'cwh:sec:' . $action . ':' . $accountId;
        if (!$this->getLock($db, $lock, 0)) {
            return 'This action is already in progress for your account; wait a moment and retry.';
        }
        $globalLock = null;
        if ($global) {
            $globalLock = 'cwh:sec:' . $action . ':global';
            if (!$this->getLock($db, $globalLock, 0)) {
                $this->releaseLock($db, $lock);
                return 'Another account is running this action right now; try again in a few minutes.';
            }
        }

        $last = (int) $db->scalar(
            "SELECT `{$column}` FROM `security_action_locks` WHERE `account_id` = ?",
            [$accountId]
        );
        $now = time();
        if ($last > 0 && $now - $last < $cooldownSeconds) {
            $retry = $cooldownSeconds - ($now - $last);
            $this->releaseLock($db, $lock);
            if ($globalLock !== null) {
                $this->releaseLock($db, $globalLock);
            }
            return 'Please wait ' . $retry . ' second(s) before repeating this action.';
        }

        // Both columns are NOT NULL with no default: set the other one to 0
        // on insert (it is untouched on duplicate-key update).
        $wafAt = $action === 'waf' ? $now : 0;
        $avAt = $action === 'av' ? $now : 0;
        $db->statement(
            "INSERT INTO `security_action_locks` (`account_id`, `waf_last_at`, `av_last_at`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `{$column}` = VALUES(`{$column}`)",
            [$accountId, $wafAt, $avAt]
        );

        return null;
    }

    public function release(int $accountId, string $action, bool $global = false): void
    {
        if (!isset(self::COLUMNS[$action]) || $accountId <= 0) {
            return;
        }
        $db = $this->db();
        $this->releaseLock($db, 'cwh:sec:' . $action . ':' . $accountId);
        if ($global) {
            $this->releaseLock($db, 'cwh:sec:' . $action . ':global');
        }
    }

    private function db(): Connection
    {
        return $this->db ?? app('db');
    }

    private function getLock(Connection $db, string $name, int $timeout): bool
    {
        return (int) $db->scalar('SELECT GET_LOCK(?, ?)', [$name, $timeout]) === 1;
    }

    private function releaseLock(Connection $db, string $name): void
    {
        try {
            $db->scalar('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (\Throwable $e) {
            // The lock dies with the connection; a release error is harmless.
        }
    }
}
