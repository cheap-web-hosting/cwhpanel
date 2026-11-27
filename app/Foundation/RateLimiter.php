<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Foundation\Database\Connection;

class RateLimiter
{
    private Connection $db;

    private string $table = 'login_attempts';

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 300): bool
    {
        return $this->attempts($key, $decaySeconds) >= $maxAttempts;
    }

    /** Rows older than this are pruned (bounded table growth). */
    private const PRUNE_AFTER_SECONDS = 86400;

    public function attempts(string $key, int $sinceSeconds = 300): int
    {
        $this->pruneIfDue();
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `{$this->table}` WHERE `key` = ? AND `created_at` >= ?",
            [$key, date('Y-m-d H:i:s', time() - $sinceSeconds)]
        );
    }

    /**
     * Record one failed attempt.
     *
     * When `$cap` (and `$decaySeconds`) are given, the count and insert happen
     * in a single atomic statement: the subquery counts existing rows inside
     * the same statement, so concurrent callers cannot all pass a pre-check
     * and then overshoot the ceiling — the counter can never exceed `$cap`
     * regardless of concurrency. Callers that enforce a ceiling (Auth) MUST
     * pass the same cap/decay they use for tooManyAttempts().
     */
    public function hit(string $key, ?int $cap = null, ?int $decaySeconds = null): void
    {
        $this->pruneIfDue();
        $now = date('Y-m-d H:i:s');

        if ($cap !== null) {
            $decay = $decaySeconds ?? 300;
            $since = date('Y-m-d H:i:s', time() - $decay);
            $this->db->statement(
                "INSERT INTO `{$this->table}` (`key`, `created_at`)
                 SELECT ?, ?
                 FROM DUAL
                 WHERE (SELECT COUNT(*) FROM `{$this->table}` WHERE `key` = ? AND `created_at` >= ?) < ?",
                [$key, $now, $key, $since, $cap]
            );
            return;
        }

        $this->db->insert($this->table, [
            'key' => $key,
            'created_at' => $now,
        ]);
    }

    /**
     * Opportunistically delete rows older than the max decay window so the
     * table cannot grow without bound under sustained failed logins. Called on
     * a sample of attempts/hits (never on every call) to keep the write load
     * trivial on the hot path.
     */
    private function pruneIfDue(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        try {
            $this->db->delete(
                $this->table,
                '`created_at` < ?',
                [date('Y-m-d H:i:s', time() - self::PRUNE_AFTER_SECONDS)]
            );
        } catch (\Throwable $e) {
            // Pruning is best-effort; never fail an auth attempt over it.
        }
    }

    public function clear(string $key): void
    {
        $this->db->delete($this->table, '`key` = ?', [$key]);
    }

    public function remaining(string $key, int $maxAttempts = 5, int $decaySeconds = 300): int
    {
        return max(0, $maxAttempts - $this->attempts($key, $decaySeconds));
    }
}
