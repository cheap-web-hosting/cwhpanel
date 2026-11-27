<?php

declare(strict_types=1);

namespace Modules\Authentication\Services;

/**
 * Customer "active sessions" list + revocation, backed by the attributed
 * sessions table (Session::write() stores user_id/ip/agent per row).
 * Ownership is enforced in SQL (id + user_id) so a forged id can never
 * touch another user's session; the current session refuses revocation
 * (use logout, or revoke-others which spares it).
 */
class SessionService
{
    /** Freshness window, mirroring the session read() lifetime gate. */
    public static function maxAge(): int
    {
        return (int) config('session.lifetime', 120) * 60;
    }

    /**
     * Fresh sessions for a user, newest first. Each row: id, short id,
     * ip, agent, last_activity (unix), current flag.
     *
     * @return array<int, array{id:string, short:string, ip:?string, agent:?string, last_activity:int, current:bool}>
     */
    public function forUser(int $userId, ?string $currentId = null): array
    {
        $rows = app('db')->select(
            'SELECT `id`, `ip_address`, `user_agent`, `last_activity` FROM `sessions`
             WHERE `user_id` = ? AND `last_activity` > ? ORDER BY `last_activity` DESC',
            [$userId, time() - self::maxAge()]
        );
        $out = [];
        foreach ($rows as $r) {
            $id = (string) $r['id'];
            $out[] = [
                'id' => $id,
                'short' => mb_substr($id, 0, 8) . '…',
                'ip' => $r['ip_address'] !== null && $r['ip_address'] !== '' ? (string) $r['ip_address'] : null,
                'agent' => $r['user_agent'] !== null && $r['user_agent'] !== '' ? mb_substr((string) $r['user_agent'], 0, 80) : null,
                'last_activity' => (int) $r['last_activity'],
                // Plain comparison: ids are row keys, not shared secrets
                // (and hash_equals throws on length mismatch).
                'current' => $currentId !== null && $id === $currentId,
            ];
        }
        return $out;
    }

    /**
     * Revoke one session. Throws when the id is malformed, is the current
     * session, or belongs to nobody (or somebody else).
     */
    public function revoke(int $userId, string $sessionId, ?string $currentId = null): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || strlen($sessionId) > 255 || preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) !== 1) {
            throw new \RuntimeException('Invalid session.');
        }
        if ($currentId !== null && $sessionId === $currentId) {
            throw new \RuntimeException('That is your current session — use logout instead.');
        }
        $deleted = app('db')->delete('sessions', '`id` = ? AND `user_id` = ?', [$sessionId, $userId]);
        if ($deleted === 0) {
            throw new \RuntimeException('Session not found.');
        }
        audit('account.session.revoked', 'session', null, ['session' => mb_substr($sessionId, 0, 8) . '…']);
    }

    /** Revoke every session except the current one. Returns the count. */
    public function revokeOthers(int $userId, ?string $currentId = null): int
    {
        if ($currentId !== null) {
            $n = app('db')->delete(
                'sessions',
                '`user_id` = ? AND `last_activity` > ? AND `id` != ?',
                [$userId, time() - self::maxAge(), $currentId]
            );
        } else {
            $n = app('db')->delete(
                'sessions',
                '`user_id` = ? AND `last_activity` > ?',
                [$userId, time() - self::maxAge()]
            );
        }
        audit('account.session.revoked_others', 'session', null, ['count' => $n]);
        return $n;
    }
}
