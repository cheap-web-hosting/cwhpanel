<?php

declare(strict_types=1);

namespace Modules\Accounts\Services;

use App\Foundation\Provisioning\PrivilegedOperationService;

/**
 * Provisions the host-level resources backing a hosting account:
 * system user, group, home directory, quota, and a cgroup v2 slice.
 *
 * All operations are delegated to the privileged agent via
 * PrivilegedOperationService (dry-run when provisioning is disabled).
 *
 * Each operation is applied independently so that a single environmental
 * failure (e.g. quota not enabled on a filesystem) does not prevent the rest
 * of the account from being provisioned.
 */
class AccountProvisioner
{
    private PrivilegedOperationService $ops;

    public function __construct(PrivilegedOperationService $ops)
    {
        $this->ops = $ops;
    }

    public function provision(array $account, array $package): array
    {
        return $this->apply($this->plan($account, $package));
    }

    /**
     * Build the provisioning operations without executing them. Kept separate
     * so package-driven defaults (PHP version, PHP pool caps) are testable
     * without touching the system.
     *
     * @return array<int,array{0:string,1:array}>
     */
    public function plan(array $account, array $package): array
    {
        $username = $account['username'];
        $home = $account['home_dir'] ?? "/home/{$username}";

        $steps = [
            ['selinux.httpd.homedirs', []],
            ['group.create', ['group' => $username]],
            ['user.create', ['username' => $username, 'home' => $home, 'group' => $username]],
            ['directory.create', ['path' => $home]],
            ['directory.create', ['path' => $home . '/public_html']],
            ['directory.create', ['path' => $home . '/logs']],
            ['directory.create', ['path' => $home . '/tmp']],
            ['directory.chown', ['owner' => $username, 'path' => $home]],
            // Homes are 0711 (owner rwx, traverse-only for the shared nginx
            // front-door + FPM): useradd makes them 0700, under which every
            // non-isolated (direct-vhost) site 404s on traversal. 0711 is the
            // shared-hosting standard; listing stays owner-only. file.chmod
            // takes account-relative paths (3-digit modes): "." is the home.
            ['file.chmod', ['account' => $username, 'path' => '.', 'mode' => '711']],
            ['selinux.restorecon', ['path' => $home]],
        ];

        // Apply an MCS category to the account home for finer isolation when one
        // has been assigned (accounts.mcs_category > 0).
        $category = (int) ($account['mcs_category'] ?? 0);
        if ($category > 0 && $category <= 1023) {
            $steps[] = ['selinux.mcs.label', ['path' => $home, 'category' => 'c' . $category]];
        }

        $defaultPhp = \Modules\Core\Services\PhpVersionsService::isAvailable((string) ($package['php_version'] ?? ''))
            ? \Modules\Core\Services\PhpVersionsService::id((string) $package['php_version'])
            : '84';

        // Package PHP ceilings also apply to the account's first master/pool,
        // so a brand-new account is capped before its first website exists.
        $phpMaxChildren = (int) ($package['php_max_children'] ?? 0);
        $phpMaxChildren = $phpMaxChildren > 0 ? min(64, $phpMaxChildren) : 8;
        $phpDefaults = array_filter([
            'set_memory_limit' => (int) ($package['php_memory_limit_mb'] ?? 0) > 0
                ? (int) $package['php_memory_limit_mb'] . 'M' : null,
            'set_max_execution_time' => (int) ($package['php_max_execution_time'] ?? 0) > 0
                ? (string) (int) $package['php_max_execution_time'] : null,
        ]);

        $steps[] = ['php.pool.write', array_merge([
            'account' => $username,
            'version' => $defaultPhp,
            'base_dir' => $home,
            'max_children' => (string) $phpMaxChildren,
        ], $phpDefaults)];

        $steps[] = ['quota.set', [
            'username' => $username,
            'soft_mb' => (int) ($package['disk_quota_mb'] ?? 0),
            'hard_mb' => (int) ($package['disk_quota_mb'] ?? 0) + 100,
            'inode_soft' => (int) ($package['disk_inodes'] ?? 0),
            'inode_hard' => (int) ($package['disk_inodes'] ?? 0) > 0
                ? (int) $package['disk_inodes'] + 1000 : 0,
        ]];
        $steps[] = ['systemd.slice.create', [
            'name' => $username,
            'memory_mb' => (int) ($package['max_memory_mb'] ?? 0),
            'cpu_percent' => (int) ($package['max_cpu_percent'] ?? 100),
            'tasks_max' => (int) ($package['max_processes'] ?? 100),
            'io_mbs' => (int) ($package['max_io_mbs'] ?? 0),
            'io_iops' => (int) ($package['max_iops'] ?? 0),
        ]];
        // Delegate the account's systemd user manager into the slice: SSH and
        // FTP sessions (PAM) and cron jobs (systemd-run --user) then inherit
        // the account's CPU/memory/process/IO caps instead of running outside
        // them. Linger keeps the manager up at boot, so cron is capped even
        // without any prior login.
        $steps[] = ['slice.userenv.ensure', ['username' => $username]];

        // Jailed SSH by default: every provisioned account lands chrooted
        // (Full access is no longer offered). Needs the home tree above.
        $steps[] = ['ssh.jail.create', ['account' => $username]];

        // Outbound direct-to-MX SMTP is closed per account (spam must flow
        // through postfix); localhost submission stays open.
        $steps[] = ['firewall.smtp.egress.block', ['account' => $username]];

        return $steps;
    }

    public function suspend(string $username): array
    {
        return $this->apply([
            ['user.lock', ['username' => $username]],
        ]);
    }

    public function unsuspend(string $username): array
    {
        return $this->apply([
            ['user.unlock', ['username' => $username]],
        ]);
    }

    public function terminate(string $username): array
    {
        $steps = [];
        // Jail first (umount + fstab strip + rm + degroup; idempotent).
        $steps[] = ['ssh.jail.destroy', ['account' => $username]];
        // Pools are per (account, version); teardown is idempotent, so sweep
        // every known version rather than tracking which ones were created.
        foreach (['84', '74', '80', '81', '82', '83'] as $ver) {
            $steps[] = ['php.pool.delete', ['account' => $username, 'version' => $ver]];
        }
        // System-level steps need the system account to exist; for DB-only
        // (never provisioned) accounts — e.g. the seed's demo row — silently
        // skip them instead of failing the whole teardown.
        $hasUser = posix_getpwnam($username) !== false;
        if ($hasUser) {
            $steps[] = ['firewall.smtp.egress.unblock', ['account' => $username]];
        }
        $steps[] = ['slice.userenv.remove', ['username' => $username]];
        $steps[] = ['systemd.slice.delete', ['name' => $username]];
        if ($hasUser) {
            $steps[] = ['user.delete', ['username' => $username]];
        }
        if ($this->groupIsDeletable($username)) {
            $steps[] = ['group.delete', ['group' => $username]];
        }
        return $this->apply($steps);
    }

    /**
     * Whether a group can be safely deleted: it must exist AND its gid must
     * not be any user's primary group (groupdel exits 8 otherwise — e.g. a
     * recycled gid after an account whose group object outlived its user).
     */
    private function groupIsDeletable(string $group): bool
    {
        $gr = posix_getgrnam($group);
        if ($gr === false) {
            return false;
        }
        $gid = (int) $gr['gid'];
        $passwd = @file('/etc/passwd');
        if ($passwd === false) {
            return true; // unreadable: let the operation attempt it
        }
        foreach ($passwd as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 4 && (int) $parts[3] === $gid) {
                return false;
            }
        }
        return true;
    }

    /**
     * Run each operation, capturing success/failure without aborting the batch.
     *
     * @return array<int, array{operation:string, status:string, error?:string}>
     */
    private function apply(array $operations): array
    {
        $results = [];

        foreach ($operations as [$operation, $args]) {
            try {
                $result = $this->ops->execute($operation, $args);
                $results[] = [
                    'operation' => $operation,
                    'status' => $result['status'],
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'operation' => $operation,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Count failed operations in a result set.
     */
    public static function failures(array $results): int
    {
        return count(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'error'));
    }
}
