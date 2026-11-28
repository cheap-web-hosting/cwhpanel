<?php

declare(strict_types=1);

namespace App\Foundation\Provisioning;

/**
 * Coalesces global nginx reloads across accounts (security audit #23).
 *
 * Customer WAF changes write their vhost with `defer_reload=1` (the agent
 * still runs `nginx -t` and rolls back on failure) and then call
 * schedule(). The first caller queues a single `nginx.reload.coalesced` job a
 * couple of seconds out; concurrent callers reuse it, so ten tenants changing
 * WAF settings at once cause ONE `systemctl reload nginx`, not ten.
 *
 * A MariaDB named lock makes the check-then-insert atomic across PHP workers.
 * schedule() returns false when the queue cannot be used, and the caller must
 * then reload synchronously (never leave a written vhost unloaded).
 */
class NginxReloadCoalescer
{
    public const JOB_TYPE = 'nginx.reload.coalesced';

    /** Delay before the deferred reload runs (the worker polls every 2s). */
    public const DELAY_SECONDS = 2;

    public static function schedule(int $delaySeconds = self::DELAY_SECONDS): bool
    {
        $db = app('db');
        $lock = 'cwh:nginx:reload:coalesce';
        try {
            if ((int) $db->scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
                // Another request is enqueueing right now; its job will see
                // this request's on-disk config too (the write already ran).
                return true;
            }
        } catch (\Throwable $e) {
            app('logger')->warning('nginx reload coalescing lock failed: ' . $e->getMessage());
            return false;
        }

        try {
            $pending = (int) $db->scalar(
                "SELECT COUNT(*) FROM `jobs` WHERE `type` = ? AND `status` = 'pending'",
                [self::JOB_TYPE]
            );
            if ($pending > 0) {
                return true;
            }
            $now = date('Y-m-d H:i:s');
            $db->insert('jobs', [
                'type' => self::JOB_TYPE,
                'status' => 'pending',
                'payload' => json_encode(['requested_at' => $now]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 3,
                'available_at' => date('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
                'created_at' => $now,
            ]);
            return true;
        } catch (\Throwable $e) {
            app('logger')->warning('nginx reload coalescing failed: ' . $e->getMessage());
            return false;
        } finally {
            try {
                $db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (\Throwable $e) {
                // The lock dies with the connection; nothing else to do.
            }
        }
    }
}
