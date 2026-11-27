<?php

declare(strict_types=1);

namespace Modules\Websites\Services;

/**
 * PrivacyService — Directory Privacy (password-protected folders).
 *
 * Protected paths + users live in website_protected_dirs / website_dir_users.
 * Passwords are stored bcrypt-hashed (nginx auth_basic understands $2y$).
 * The htpasswd file sits outside the docroot at
 * /home/<user>/.htpasswds/site<websiteId>-<dirId> and is rewritten on every
 * change; the vhost is then regenerated isolation-aware (front-door proxy
 * when the account worker is enabled, direct write otherwise).
 */
class PrivacyService
{
    public static function htpasswdPath(string $username, int $websiteId, int $dirId): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,30}$/', strtolower($username))) {
            throw new \RuntimeException('Invalid username.');
        }
        return '/home/' . strtolower($username) . '/.htpasswds/site' . $websiteId . '-' . $dirId;
    }

    public static function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || strlen($path) > 128
            || str_contains($path, '..') || str_contains($path, "\n") || str_contains($path, ';')
            || !preg_match('#^[A-Za-z0-9._/-]+$#', $path)
        ) {
            throw new \RuntimeException('Invalid protected path.');
        }
        return $path;
    }

    /** @return array{dirs:array<int,array>} */
    public function forWebsite(int $websiteId): array
    {
        $dirs = app('db')->select(
            'SELECT * FROM `website_protected_dirs` WHERE `website_id` = ? ORDER BY `path`',
            [$websiteId]
        );
        foreach ($dirs as &$d) {
            $d['users'] = app('db')->select(
                'SELECT `id`, `username`, `suspended` FROM `website_dir_users` WHERE `protected_dir_id` = ? ORDER BY `username`',
                [(int) $d['id']]
            );
        }
        unset($d);
        return ['dirs' => $dirs];
    }

    public function addDir(int $websiteId, string $path, string $title): int
    {
        $path = self::normalizePath($path);
        $title = trim(preg_replace('/\s+/', ' ', $title));
        if ($title === '' || strlen($title) > 128) {
            throw new \RuntimeException('Title required (max 128 chars).');
        }
        if (app('db')->selectOne(
            'SELECT `id` FROM `website_protected_dirs` WHERE `website_id` = ? AND `path` = ?',
            [$websiteId, $path]
        )) {
            throw new \RuntimeException('Path already protected.');
        }
        return (int) app('db')->insert('website_protected_dirs', [
            'website_id' => $websiteId, 'path' => $path, 'title' => $title, 'enabled' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteDir(int $dirId): void
    {
        $dir = $this->findDir($dirId);
        if ($dir === null) {
            return;
        }
        app('db')->delete('website_dir_users', '`protected_dir_id` = ?', [$dirId]);
        app('db')->delete('website_protected_dirs', '`id` = ?', [$dirId]);
        $this->removeHtpasswd($dir);
        $this->regenerate((int) $dir['website_id']);
    }

    public function toggleDir(int $dirId, bool $enabled): void
    {
        $dir = $this->findDir($dirId);
        if ($dir === null) {
            return;
        }
        app('db')->update('website_protected_dirs', [
            'enabled' => $enabled ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$dirId]);
        $this->syncHtpasswd((int) $dir['website_id']);
        $this->regenerate((int) $dir['website_id']);
    }

    public function addUser(int $dirId, string $username, string $password): void
    {
        $dir = $this->findDir($dirId);
        if ($dir === null) {
            throw new \RuntimeException('Protected directory not found.');
        }
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9._-]{1,64}$/', $username)) {
            throw new \RuntimeException('Invalid username.');
        }
        if (strlen($password) < 8) {
            throw new \RuntimeException('Password must be at least 8 characters.');
        }
        if (app('db')->selectOne(
            'SELECT `id` FROM `website_dir_users` WHERE `protected_dir_id` = ? AND `username` = ?',
            [$dirId, $username]
        )) {
            throw new \RuntimeException('User already exists.');
        }
        app('db')->insert('website_dir_users', [
            'protected_dir_id' => $dirId, 'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->syncHtpasswd((int) $dir['website_id']);
        $this->regenerate((int) $dir['website_id']);
    }

    public function deleteUser(int $dirId, int $userId): void
    {
        $dir = $this->findDir($dirId);
        if ($dir === null) {
            return;
        }
        app('db')->delete('website_dir_users', '`id` = ? AND `protected_dir_id` = ?', [$userId, $dirId]);
        $this->syncHtpasswd((int) $dir['website_id']);
        $this->regenerate((int) $dir['website_id']);
    }

    /**
     * Rewrite every htpasswd file of a website from DB state.
     */
    public function syncHtpasswd(int $websiteId): void
    {
        $site = $this->loadSite($websiteId);
        if ($site === null) {
            return;
        }
        $dirs = app('db')->select(
            'SELECT * FROM `website_protected_dirs` WHERE `website_id` = ? AND `enabled` = 1',
            [$websiteId]
        );
        try {
            app('provisioning')->execute('file.mkdir', [
                'account' => $site['username'],
                'path' => '.htpasswds',
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('Htpasswd dir ensure failed: ' . $e->getMessage());
        }
        foreach ($dirs as $dir) {
            // Suspended users are omitted: their password stops working on
            // the next rewrite without touching the vhost.
            $users = app('db')->select(
                'SELECT `username`, `password` FROM `website_dir_users` WHERE `protected_dir_id` = ? AND `suspended` = 0 ORDER BY `username`',
                [(int) $dir['id']]
            );
            $lines = [];
            foreach ($users as $u) {
                $lines[] = $u['username'] . ':' . $u['password'];
            }
            // Account-relative path for file.write, then chown into place.
            $abs = self::htpasswdPath($site['username'], $websiteId, (int) $dir['id']);
            $rel = ltrim(substr($abs, strlen('/home/' . $site['username'] . '/')), '/');
            app('provisioning')->execute('file.write', [
                'account' => $site['username'],
                'path' => $rel,
                'content' => base64_encode(implode("\n", $lines) . "\n"),
            ]);
            app('provisioning')->execute('directory.chown', [
                'owner' => $site['username'],
                'path' => $abs,
            ]);
        }
    }

    /**
     * Regenerate the stored vhost (picks up generator changes) and push it
     * through the isolation-aware path when the account worker is enabled.
     */
    public function regenerate(int $websiteId): void
    {
        $site = $this->loadSite($websiteId);
        if ($site === null) {
            return;
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $site['account_id']]);
        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) $site['domain_id']]);
        if ($account === null || $domain === null) {
            return;
        }
        $nginx = app(NginxConfigGenerator::class)->generate($site, $domain, $account);
        app('db')->update('websites', [
            'nginx_config' => $nginx, 'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$websiteId]);

        app(\Modules\Core\Services\NginxIsolationService::class)->pushVhost($account, $domain['name'], $nginx);
    }

    private function findDir(int $dirId): ?array
    {
        return app('db')->selectOne('SELECT * FROM `website_protected_dirs` WHERE `id` = ?', [$dirId]);
    }

    private function removeHtpasswd(array $dir): void
    {
        $site = $this->loadSite((int) $dir['website_id']);
        if ($site === null) {
            return;
        }
        try {
            app('provisioning')->execute('file.delete', [
                'account' => $site['username'],
                'path' => ltrim(substr(
                    self::htpasswdPath($site['username'], (int) $dir['website_id'], (int) $dir['id']),
                    strlen('/home/' . $site['username'] . '/')
                ), '/'),
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('Htpasswd remove failed: ' . $e->getMessage());
        }
    }

    private function loadSite(int $websiteId): ?array
    {
        $site = app('db')->selectOne(
            'SELECT w.*, a.`username` AS username FROM `websites` w
             JOIN `accounts` a ON a.`id` = w.`account_id`
             WHERE w.`id` = ?',
            [$websiteId]
        );
        return $site;
    }
}
