<?php
declare(strict_types=1);
namespace Modules\Databases\Services;

/**
 * Customer database management.
 *
 * M-7: all DDL/user/grant work runs through `cwhpanel_admin` SQL SECURITY
 * DEFINER procedures (owned by root), so this account no longer needs global
 * data privileges. It holds only RELOAD/PROCESS/SHOW DATABASES globally,
 * ALL on its own `cwhpanel` schema, and EXECUTE on the procedure schema — it
 * cannot read any tenant database directly.
 */
class DatabaseService {
    private \PDO $adminPdo;

    public const REMOTE_KEY = 'mysql.remote_enabled';
    private const PROC_SCHEMA = 'cwhpanel_admin';

    public function __construct() {
        $cfg = config('database');
        $this->adminPdo = new \PDO(
            "mysql:host={$cfg['host']};port={$cfg['port']};charset={$cfg['charset']}",
            $cfg['username'], $cfg['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /** Call a DEFINER procedure that returns no rows. */
    private function call(string $proc, array $args = []): void
    {
        $ph = $args === [] ? '' : implode(',', array_fill(0, count($args), '?'));
        $stmt = $this->adminPdo->prepare("CALL `" . self::PROC_SCHEMA . "`.`{$proc}`({$ph})");
        $stmt->execute($args);
        $stmt->closeCursor();
    }

    /** Call a DEFINER procedure that returns a result set. */
    private function callRows(string $proc, array $args = []): array
    {
        $ph = $args === [] ? '' : implode(',', array_fill(0, count($args), '?'));
        $stmt = $this->adminPdo->prepare("CALL `" . self::PROC_SCHEMA . "`.`{$proc}`({$ph})");
        $stmt->execute($args);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    }

    /** Global remote-MySQL gate (Server → Firewall toggle, default off). */
    public static function remoteEnabled(): bool
    {
        try {
            $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', [self::REMOTE_KEY]);
            return trim((string) ($row['value'] ?? '')) === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function setRemoteEnabled(bool $on): void
    {
        $db = app('db');
        $now = date('Y-m-d H:i:s');
        if ($db->selectOne('SELECT `key` FROM `settings` WHERE `key` = ?', [self::REMOTE_KEY])) {
            $db->update('settings', ['value' => $on ? '1' : '0', 'updated_at' => $now], '`key` = ?', [self::REMOTE_KEY]);
        } else {
            $db->insert('settings', ['key' => self::REMOTE_KEY, 'value' => $on ? '1' : '0', 'updated_at' => $now]);
        }
    }

    public function create(string $dbName): bool {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($dbName));
        $this->call('cwh_db_create', [$name]);
        return true;
    }

    public function delete(string $dbName): bool {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($dbName));
        $this->call('cwh_db_drop', [$name]);
        return true;
    }

    public function createUser(string $username, string $password): bool {
        $user = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
        $this->call('cwh_user_create', [$user, 'localhost', $password]);
        return true;
    }

    public function deleteUser(string $username): bool {
        $user = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
        $this->call('cwh_user_drop', [$user, 'localhost']);
        foreach ($this->grantedHosts($user) as $h) {
            $this->call('cwh_user_drop', [$user, $h]);
            // Remove the narrow firewalld 3306 accept each granted IPv4 host
            // may have had, so deprovisioned hosts lose DB network access too.
            if (self::isIpv4($h)) {
                try {
                    app('provisioning')->execute('firewall.db.revoke', ['ip' => $h]);
                } catch (\Throwable $e) {
                    app('logger')->warning('Firewall MySQL revoke failed for ' . $h . ': ' . $e->getMessage());
                }
            }
        }
        return true;
    }

    /**
     * Validate a remote host for GRANT use: a literal IPv4 address or a
     * concrete hostname. MySQL wildcard patterns ('%', '*') are refused —
     * combined with the global 3306 toggle a wildcard grant would expose the
     * account database to the whole internet.
     */
    public static function sanitizeHost(string $host): string {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 255 || $host === 'localhost') {
            throw new \RuntimeException('invalid remote host');
        }
        if (str_contains($host, '%') || str_contains($host, '*')) {
            throw new \RuntimeException('wildcard remote hosts are not allowed');
        }
        if (self::isIpv4($host)) {
            return $host;
        }
        // Concrete hostname: labels of alnum/hyphen, dot-separated, no leading
        // dash and at least a two-letter TLD.
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            throw new \RuntimeException('invalid remote host');
        }
        return $host;
    }

    public static function isIpv4(string $host): bool {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Grant a database user access from a remote host. The plaintext password
     * is required (only hashes are stored) to create the 'user'@'host' login.
     * Literal IPv4 hosts also get a narrow firewall accept for 3306/tcp.
     * Refused while the global remote-MySQL toggle is off (firewall page).
     */
    public function allowHost(string $username, string $dbName, string $host, string $password): bool {
        if (!self::remoteEnabled()) {
            throw new \RuntimeException('Remote MySQL is disabled (Server → Firewall → Remote MySQL access).');
        }
        $user = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($dbName));
        $host = self::sanitizeHost($host);
        if ($user === '' || $name === '') {
            throw new \RuntimeException('invalid database user or name');
        }
        $this->call('cwh_user_create', [$user, $host, $password]);
        $this->call('cwh_user_grant', [$user, $host, $name]);
        if (self::isIpv4($host)) {
            app('provisioning')->execute('firewall.db.allow', ['ip' => $host]);
        }
        return true;
    }

    public function revokeHost(string $username, string $host): bool {
        $user = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
        $host = self::sanitizeHost($host);
        $this->call('cwh_user_drop', [$user, $host]);
        if (self::isIpv4($host)) {
            try {
                app('provisioning')->execute('firewall.db.revoke', ['ip' => $host]);
            } catch (\Throwable $e) {
                app('logger')->warning('Firewall MySQL revoke failed for ' . $host . ': ' . $e->getMessage());
            }
        }
        return true;
    }

    /** Remote hosts recorded for a DB user row (localhost is implicit). */
    public function remoteHosts(int $dbUserId): array {
        return app('db')->select(
            'SELECT * FROM `hosting_db_hosts` WHERE `db_user_id` = ? ORDER BY `host`',
            [$dbUserId]
        );
    }

    /** Hosts a MySQL user actually has grants for (excluding localhost). */
    private function grantedHosts(string $user): array {
        $rows = $this->callRows('cwh_user_hosts', [$user]);
        $out = [];
        foreach ($rows as $r) {
            $h = (string) ($r['Host'] ?? '');
            if ($h !== '' && $h !== 'localhost') {
                $out[] = $h;
            }
        }
        return array_values(array_unique($out));
    }

    /** Toggle write privileges for one login on one DB (quota enforcement). */
    public function setWriteAccess(string $dbName, string $username, bool $allowed): void
    {
        $dbName = preg_replace('/[^a-z0-9_]/', '', strtolower($dbName));
        $username = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
        if ($dbName === '' || $username === '') {
            throw new \RuntimeException('Invalid database or username.');
        }
        // The panel's MariaDB account is least-privilege (no GRANT OPTION);
        // the write toggle must go through the admin schema like every other
        // DDL/grant operation.
        $this->call('cwh_user_write_access', [$username, 'localhost', $dbName, $allowed ? 1 : 0]);
    }

    /** Current size of one customer database in bytes (DEFINER procedure:
     *  the panel account is least-privilege and cannot read other schemas). */
    public function databaseBytes(string $dbName): int
    {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($dbName));
        if ($name === '') {
            return 0;
        }
        $rows = $this->callRows('cwh_db_size', [$name]);
        return (int) ($rows[0]['bytes'] ?? 0);
    }

    public function grantAll(string $username, string $dbName): bool {
        $user = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($dbName));
        $this->call('cwh_user_grant', [$user, 'localhost', $name]);
        return true;
    }

    public function rotatePassword(string $username, string $password): bool {
        $user = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
        $this->call('cwh_user_password', [$user, 'localhost', $password]);
        // Keep every remote login for this user on the same password.
        foreach ($this->grantedHosts($user) as $h) {
            $this->call('cwh_user_password', [$user, $h, $password]);
        }
        return true;
    }

    public function size(string $dbName): int {
        try {
            foreach ($this->callRows('cwh_db_sizes') as $r) {
                if (($r['db_name'] ?? '') === $dbName) {
                    return (int) ($r['bytes'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
        }
        return 0;
    }
}
