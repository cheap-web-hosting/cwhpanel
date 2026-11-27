<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Databases\Services\DatabaseService;

/**
 * Reads actual per-account disk usage (bytes) as the sum of:
 *   1. the account home directory size (`du -sb`, via the privileged agent), and
 *   2. the total size of the account's databases (information_schema, via PDO).
 *
 * The agent returns a single line "<bytes>\t<path>"; we parse the leading number.
 * When provisioning is disabled the agent call returns a dry-run status with no
 * output, so the file-size component is treated as unavailable. In that case the
 * result is null (callers show "not measured") rather than a misleading DB-only
 * figure.
 */
class DiskUsageService
{
    public function bytesForAccount(int $accountId): ?int
    {
        $fileBytes = $this->homeBytes($accountId);
        if ($fileBytes === null) {
            // File size is the authoritative component; without it we cannot
            // report a meaningful total, so signal "unavailable".
            return null;
        }

        return $fileBytes + $this->databaseBytes($accountId);
    }

    private function homeBytes(int $accountId): ?int
    {
        $row = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `id` = ?', [$accountId]);
        if ($row === null) {
            return null;
        }

        try {
            $result = app('provisioning')->execute('disk.usage', [
                'account' => $row['username'],
                'path' => '.',
            ]);
        } catch (\Throwable $e) {
            // Provisioning errors must not 500 the customer dashboard; report
            // usage as unavailable instead (shows "not measured").
            app('logger')->warning('Disk usage probe failed for ' . $row['username'] . ': ' . $e->getMessage());
            return null;
        }

        if (($result['status'] ?? '') !== 'success') {
            return null;
        }

        $output = trim((string) ($result['output'] ?? ''));
        if ($output === '') {
            return null;
        }

        // `du -sb` prints "<size>\t<path>"; take the integer before whitespace.
        $size = preg_split('/\s+/', $output)[0] ?? '';
        if ($size === '' || !ctype_digit($size)) {
            return null;
        }

        return (int) $size;
    }

    private function databaseBytes(int $accountId): int
    {
        $names = app('db')->select(
            'SELECT `name` FROM `hosting_databases` WHERE `account_id` = ?',
            [$accountId]
        );

        $total = 0;

        try {
            $db = app(DatabaseService::class);
            foreach ($names as $row) {
                $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($row['name'] ?? '')));
                if ($name === '') {
                    continue;
                }
                $total += $db->size($name);
            }
        } catch (\Throwable $e) {
            // Database size is best-effort; a DB error must not fail disk reporting.
        }

        return $total;
    }

    /**
     * XFS quota usage/limits for an account (bytes + inodes), from the agent's
     * `quota.usage` op. Null when the agent is unreachable or the filesystem
     * carries no user quota (callers show "not measured", never fake zeros).
     *
     * @return array{block_used:int, block_soft:int, block_hard:int, inode_used:int, inode_soft:int, inode_hard:int}|null
     */
    public function quotaUsage(int $accountId): ?array
    {
        $row = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `id` = ?', [$accountId]);
        if ($row === null) {
            return null;
        }
        try {
            $result = app('provisioning')->execute('quota.usage', ['username' => (string) $row['username']]);
        } catch (\Throwable $e) {
            return null;
        }
        $out = trim((string) ($result['output'] ?? ''));
        if (($result['status'] ?? '') !== 'success' || $out === '') {
            return null;
        }
        if (preg_match('/^\s*\S+\s+(\d+)\s+(\d+)\s+(\d+)\s+(?:\S+\s+)?(\d+)\s+(\d+)\s+(\d+)/m', $out, $m) !== 1) {
            return null;
        }

        return [
            'block_used' => (int) $m[1] * 1024,
            'block_soft' => (int) $m[2] * 1024,
            'block_hard' => (int) $m[3] * 1024,
            'inode_used' => (int) $m[4],
            'inode_soft' => (int) $m[5],
            'inode_hard' => (int) $m[6],
        ];
    }
}