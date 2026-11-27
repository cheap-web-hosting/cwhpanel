<?php

declare(strict_types=1);

namespace Modules\Websites\Services;

/**
 * LeechService — leech protection for Directory Privacy areas.
 *
 * Password sharing shows up in the access logs as one basic-auth user
 * succeeding from many client IPs. Each protected dir may set max_logins
 * (distinct IPs per user per trailing 24h); users over the limit appear as
 * suspects. Suspending a user drops them from the htpasswd rewrite, so the
 * shared password stops working without touching the vhost.
 */
class LeechService
{
    public const WINDOW_SECONDS = 86400;
    public const MAX_LIMIT = 1000;

    /**
     * Parse combined-format access log text into per-user IP hit counts for
     * successful (2xx) hits under the given path prefixes. Pure + testable.
     *
     * @param array<int,string> $paths URL prefixes like "/private/"
     * @return array<string,array<string,int>> user => [ip => hits]
     */
    public static function parse(string $text, array $paths): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $m = [];
            if (!preg_match(
                '/^(\S+) \S+ (\S+) \[([^\]]+)\] "(\S+)\s+(\S+)[^"]*" (\d{3}) \S+ "[^"]*" "[^"]*"( "([^"]*)")?/',
                $line,
                $m
            )) {
                continue;
            }
            [$full, $ip, $user, $time, $method, $url, $status] = $m + [null, null, null, null, null, null, null];
            if ($user === null || $user === '' || $user === '-') {
                continue;
            }
            if ((int) $status < 200 || (int) $status >= 300) {
                continue;
            }
            $path = explode('?', (string) $url, 2)[0];
            $hit = false;
            foreach ($paths as $prefix) {
                if (str_starts_with($path . '/', rtrim($prefix, '/') . '/')) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $dt = \DateTime::createFromFormat('d/M/Y:H:i:s O', (string) $time);
            if ($dt !== false && (time() - $dt->getTimestamp()) > self::WINDOW_SECONDS) {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $out[$user] = $out[$user] ?? [];
            $out[$user][$ip] = ($out[$user][$ip] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * Users over their dir's max_logins in the visible log window.
     *
     * @return array<int,array{dir_id:int, path:string, username:string, user_id:?int, ips:array<int,string>, hits:int, max:int}>
     */
    public function suspects(int $websiteId): array
    {
        $site = app('db')->selectOne('SELECT * FROM `websites` WHERE `id` = ?', [$websiteId]);
        if ($site === null) {
            return [];
        }
        $dirs = app('db')->select(
            'SELECT * FROM `website_protected_dirs` WHERE `website_id` = ? AND `enabled` = 1 AND `max_logins` IS NOT NULL AND `max_logins` > 0',
            [$websiteId]
        );
        if ($dirs === []) {
            return [];
        }
        try {
            $tails = app(\Modules\Monitoring\Services\TrafficService::class)->tailLogs($site, 2097152);
        } catch (\Throwable $e) {
            return [];
        }
        $access = (string) ($tails['access'] ?? '');
        if ($access === '') {
            return [];
        }
        $found = [];
        foreach ($dirs as $dir) {
            $prefix = '/' . trim((string) ($dir['path'] ?? ''), '/') . '/';
            $max = max(1, min(self::MAX_LIMIT, (int) $dir['max_logins']));
            foreach (self::parse($access, [$prefix]) as $user => $ips) {
                if (count($ips) > $max) {
                    $row = app('db')->selectOne(
                        'SELECT `id` FROM `website_dir_users` WHERE `protected_dir_id` = ? AND `username` = ?',
                        [(int) $dir['id'], $user]
                    );
                    $found[] = [
                        'dir_id' => (int) $dir['id'],
                        'path' => trim((string) ($dir['path'] ?? ''), '/'),
                        'username' => $user,
                        'user_id' => $row !== null ? (int) $row['id'] : null,
                        'ips' => array_keys($ips),
                        'hits' => array_sum($ips),
                        'max' => $max,
                    ];
                }
            }
        }
        return $found;
    }

    /** @throws \RuntimeException */
    public function setMaxLogins(int $dirId, ?int $max): void
    {
        if ($max !== null && ($max < 1 || $max > self::MAX_LIMIT)) {
            throw new \RuntimeException('Max logins must be 1-' . self::MAX_LIMIT . ' (empty = off).');
        }
        app('db')->update('website_protected_dirs', [
            'max_logins' => $max, 'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$dirId]);
    }

    public function suspend(int $userId): void
    {
        $this->setSuspended($userId, true);
    }

    public function unsuspend(int $userId): void
    {
        $this->setSuspended($userId, false);
    }

    private function setSuspended(int $userId, bool $suspended): void
    {
        $row = app('db')->selectOne('SELECT * FROM `website_dir_users` WHERE `id` = ?', [$userId]);
        if ($row === null) {
            throw new \RuntimeException('User not found.');
        }
        app('db')->update('website_dir_users', [
            'suspended' => $suspended ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$userId]);
        $dir = app('db')->selectOne('SELECT * FROM `website_protected_dirs` WHERE `id` = ?', [(int) $row['protected_dir_id']]);
        if ($dir !== null) {
            app(PrivacyService::class)->syncHtpasswd((int) $dir['website_id']);
        }
    }
}
