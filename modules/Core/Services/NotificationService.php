<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * In-app notifications (the topbar bell) for admin and customer users.
 *
 * Notifications are per-user rows in `notifications`. Sources: job failures
 * / app job completion (JobRunner), watchdog incidents, new critical/high
 * vulnerabilities, bandwidth suspensions. Non-essential — a failed insert is
 * logged and swallowed, never fatal to the caller.
 */
class NotificationService
{
    /** Push a notification to one user. */
    public function push(int $userId, string $type, string $title, string $body = '', ?string $url = null): void
    {
        try {
            app('db')->insert('notifications', [
                'user_id' => $userId,
                'type' => mb_substr($type, 0, 32),
                'title' => mb_substr($title, 0, 255),
                'body' => mb_substr($body, 0, 2000),
                'url' => $url !== null ? mb_substr($url, 0, 255) : null,
                'read_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('Notification push failed: ' . $e->getMessage());
        }
    }

    /** Push to the panel administrator (role admin / first admin user). */
    public function pushAdmin(string $type, string $title, string $body = '', ?string $url = null): void
    {
        $id = app('db')->scalar("SELECT `id` FROM `users` WHERE `is_admin` = 1 ORDER BY `id` LIMIT 1");
        if ($id !== null) {
            $this->push((int) $id, $type, $title, $body, $url);
        }
    }

    /** Push to the user that owns a hosting account (username), if any. */
    public function pushAccountOwner(string $username, string $type, string $title, string $body = '', ?string $url = null): void
    {
        $id = app('db')->scalar("SELECT `user_id` FROM `accounts` WHERE `username` = ? LIMIT 1", [$username]);
        if ($id !== null) {
            $this->push((int) $id, $type, $title, $body, $url);
        }
    }

    public function unreadCount(int $userId): int
    {
        return (int) app('db')->scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE `user_id` = ? AND `read_at` IS NULL',
            [$userId]
        );
    }

    /** Recent notifications for a user (unread first, newest first). */
    public function recent(int $userId, int $limit = 20): array
    {
        return app('db')->select(
            'SELECT * FROM `notifications` WHERE `user_id` = ?
             ORDER BY `read_at` IS NOT NULL, `id` DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId]
        );
    }

    /** Mark one notification read (only if owned by the user). */
    public function markRead(int $userId, int $id): void
    {
        app('db')->update(
            'notifications',
            ['read_at' => date('Y-m-d H:i:s')],
            '`id` = ? AND `user_id` = ? AND `read_at` IS NULL',
            [$id, $userId]
        );
    }

    public function markAllRead(int $userId): int
    {
        return app('db')->update(
            'notifications',
            ['read_at' => date('Y-m-d H:i:s')],
            '`user_id` = ? AND `read_at` IS NULL',
            [$userId]
        );
    }
}
