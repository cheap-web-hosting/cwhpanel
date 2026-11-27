<?php

declare(strict_types=1);

namespace Modules\Ftp\Services;

/**
 * Backs each ftp_accounts row with a dedicated system login sharing the
 * account's UID/GID (useradd -o): identical file ownership and quota
 * attribution as the account itself, but an independent password, home
 * directory (chrooted via user_config_dir) and lock state per row.
 *
 * This replaced the old model where every row overwrote the ACCOUNT system
 * password (last writer won — including SSH) while per-row passwords, home
 * dirs, suspend flags and quotas sat unenforced in the database.
 */
class FtpService
{
    public function setup(): bool
    {
        $r = app('provisioning')->execute('ftp.setup', ['guest' => 'ftpguest']);
        return $r['status'] === 'success';
    }

    /** Resolve an account row to [username, uid, gid]. Throws when missing. */
    private function accountIds(array $account): array
    {
        $username = (string) ($account['username'] ?? '');
        if ($username === '') {
            throw new \RuntimeException('Account has no username.');
        }
        $pw = posix_getpwnam($username);
        if ($pw === false) {
            throw new \RuntimeException("System user {$username} is missing.");
        }
        return [$username, (int) $pw['uid'], (int) $pw['gid']];
    }

    /**
     * Names that must never back an FTP row: they would let system.password.set
     * / ftp.login.* target a pre-existing system account (root, nginx, mysql…).
     * Mirrors the agent's RESERVED_USERNAMES; the agent also rejects any
     * existing uid<1000 login as a second line of defense.
     */
    private const RESERVED_USERNAMES = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'uucp',
        'operator', 'nobody', 'adm', 'postmaster', 'mysql', 'mariadb', 'postfix', 'dovecot',
        'nginx', 'apache', 'httpd', 'php-fpm', 'systemd', 'sshd', 'chrony', 'dbus', 'polkitd',
        'rpc', 'rpcuser', 'nfsnobody', 'tss', 'setroubleshoot', 'colord', 'saslauth', 'pesign',
        'gluster', 'sssd', 'tcpdump', 'unbound', 'clevis', 'cockpit-ws', 'flatpak', 'pipewire',
        'geoclue', 'rtkit', 'qemu', 'libvirt', 'saned', 'abrt', 'usbmuxd', 'openvpn', 'mailnull',
        'smmsp', 'ftpguest', 'cwhpanel', 'haldaemon', 'vcsa', 'systemd-coredump',
        'systemd-resolve', 'systemd-network', 'systemd-timesync', 'gnome-initial-setup',
    ];

    /**
     * Validate an FTP login name at creation time. Returns the normalized name,
     * or throws when the name is malformed, reserved, or already a system user.
     * The main account's own login is allowed (the shared-UID main row).
     */
    public static function assertCreatableUsername(string $name, string $accountUsername): string
    {
        $name = strtolower(trim($name));
        if ($name === '' || preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $name) !== 1) {
            throw new \RuntimeException('FTP username must start with a letter and use only a-z, 0-9, - and _ (max 31).');
        }
        if ($name === strtolower(trim($accountUsername))) {
            return $name;
        }
        if (in_array($name, self::RESERVED_USERNAMES, true)) {
            throw new \RuntimeException('That username is reserved.');
        }
        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($name);
            if ($pw !== false && (int) ($pw['uid'] ?? 0) < 1000) {
                throw new \RuntimeException('That username belongs to a system account.');
            }
        }
        return $name;
    }

    /** Home dirs must stay inside the account tree (chroot escape guard). */
    public static function assertHomeInTree(string $accountUsername, string $home): string
    {
        $home = '/' . trim($home, '/');
        $base = '/home/' . $accountUsername;
        if ($home !== $base && !str_starts_with($home, $base . '/')) {
            throw new \RuntimeException('FTP home must live inside /home/' . $accountUsername . '.');
        }
        return $home;
    }

    /**
     * Provision the full login: home dir (account-owned) + system user
     * (shared UID/GID) + password + vsftpd chroot mapping.
     */
    public function provisionLogin(array $account, string $ftpUsername, string $home, string $password): void
    {
        [$username, $uid, $gid] = $this->accountIds($account);
        $home = self::assertHomeInTree($username, $home);
        app('provisioning')->execute('directory.create', ['path' => $home]);
        app('provisioning')->execute('directory.chown', ['owner' => $username, 'path' => $home]);
        app('provisioning')->execute('ftp.login.create', [
            'account' => $username,
            'username' => $ftpUsername,
            'uid' => (string) $uid,
            'gid' => (string) $gid,
            'home' => $home,
        ]);
        // The vsftpd chroot mapping must exist BEFORE system.password.set: the
        // agent's ownership assertion proves this login belongs to the account
        // via /etc/vsftpd/user_conf/<user> (local_root under /home/<account>/).
        $this->syncUser($ftpUsername, $home, $username);
        $this->setPassword($ftpUsername, $password, $username);
    }

    public function setPassword(string $ftpUsername, string $password, ?string $account = null): bool
    {
        $r = app('provisioning')->execute('system.password.set', [
            'account' => $account ?? $ftpUsername,
            'username' => $ftpUsername,
            'password' => $password,
        ]);
        return $r['status'] === 'success';
    }

    public function lock(string $ftpUsername): bool
    {
        $r = app('provisioning')->execute('user.lock', ['username' => $ftpUsername]);
        return $r['status'] === 'success';
    }

    public function unlock(string $ftpUsername): bool
    {
        $r = app('provisioning')->execute('user.unlock', ['username' => $ftpUsername]);
        return $r['status'] === 'success';
    }

    /**
     * Point a login at its chroot home for vsftpd
     * (user_config_dir/<user>). No daemon reload: vsftpd reads it per login.
     * Skipped (true) for names that are not system users: vsftpd authenticates
     * via system PAM, so a virtual-only row could never log in regardless.
     */
    public function syncUser(string $systemUsername, string $home, ?string $accountUsername = null): bool
    {
        if (posix_getpwnam($systemUsername) === false) {
            app('logger')->warning("FTP chroot skipped for {$systemUsername}: not a system user.");
            return true;
        }        $args = [
            'username' => $systemUsername,
            'home' => $home,
        ];
        if ($accountUsername !== null && $accountUsername !== '') {
            $args['account'] = $accountUsername;
        }
        $r = app('provisioning')->execute('ftp.user.write', $args);
        return $r['status'] === 'success';
    }

    /** Remove the login (system user, files kept) + its chroot override. */
    public function removeLogin(string $ftpUsername): void
    {
        try {
            app('provisioning')->execute('ftp.login.delete', ['username' => $ftpUsername]);
        } catch (\Throwable $e) {
            app('logger')->warning("FTP login delete failed for {$ftpUsername}: " . $e->getMessage());
        }
        try {
            app('provisioning')->execute('ftp.user.delete', ['username' => $ftpUsername]);
        } catch (\Throwable $e) {
        }
    }

    /** Usage bytes of an FTP home (for the quota sweep). Null when unreadable. */
    public function homeUsage(string $accountUsername, string $homeRel): ?int
    {
        try {
            $result = app('provisioning')->execute('disk.usage', [
                'account' => $accountUsername,
                'path' => $homeRel,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (($result['status'] ?? '') !== 'success') {
            return null;
        }
        // `du -sb` prints "<size>\t<path>"; take the integer before whitespace.
        $size = preg_split('/\s+/', trim((string) ($result['output'] ?? '')))[0] ?? '';
        if ($size === '' || !ctype_digit($size)) {
            return null;
        }
        return (int) $size;
    }
}
