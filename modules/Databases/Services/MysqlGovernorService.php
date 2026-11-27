<?php

declare(strict_types=1);

namespace Modules\Databases\Services;

/**
 * MySQL Governor equivalent (CloudLinux Governor, panel half).
 *
 * Two enforcement layers, both driven by the hosting package:
 *
 *   1. Server-enforced caps — MAX_USER_CONNECTIONS / MAX_QUERIES_PER_HOUR /
 *      MAX_UPDATES_PER_HOUR applied with ALTER USER ... WITH on every login
 *      of every panel-managed DB user (localhost + remote hosts). 0 means
 *      "unlimited", matching MySQL semantics. Applied at user creation and
 *      re-applied to a whole package on package save (see PackageController).
 *      rotatePassword needs no hook: ALTER USER ... IDENTIFIED BY preserves
 *      the WITH resource options.
 *   2. Killer sweep — sweep() runs every minute as the `mysql-governor-sweep`
 *      cron job (scripts/mysql-governor-sweep.php): any Query/Execute from a
 *      panel-managed user running
 *      longer than the package's mysql_max_statement_sec is KILL QUERYed
 *      (the connection survives; only the runaway statement dies) and logged
 *      to mysql_governor_events.
 *
 * Safety: only usernames present in hosting_databases/hosting_db_users are
 * ever touched; the sweep's own connection, replication threads, the event
 * scheduler and Sleep (idle) threads are never killed. Failures degrade to
 * "no enforcement", never to dropped databases.
 *
 * Privileges required by the panel DB login (same context DatabaseService
 * already needs): PROCESS (see other users' threads) + SUPER/CONNECTION_ADMIN
 * (KILL other users' queries) + CREATE USER/ALTER (WITH caps). Without
 * PROCESS the sweep only sees its own threads and kills nothing.
 */
class MysqlGovernorService
{
    /** Hard ceiling: never kill faster than this, whatever the package says. */
    public const MIN_STATEMENT_SEC = 5;

    /** Lenient fail-open limits when an account has no package row (legacy). */
    public const DEFAULT_LIMITS = [
        'mysql_max_user_connections' => 50,
        'mysql_max_queries_per_hour' => 0,
        'mysql_max_updates_per_hour' => 0,
        'mysql_max_statement_sec' => 300,
    ];

    private \PDO $adminPdo;

    public function __construct()
    {
        $cfg = config('database');
        $this->adminPdo = new \PDO(
            "mysql:host={$cfg['host']};port={$cfg['port']};charset={$cfg['charset']}",
            $cfg['username'], $cfg['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /** Effective governor limits for an account (package row or defaults). */
    /** Call a DEFINER procedure (M-7): the panel has no direct ALTER USER. */
    private function callProc(string $proc, array $args = []): void
    {
        $ph = $args === [] ? '' : implode(',', array_fill(0, count($args), '?'));
        $stmt = $this->adminPdo->prepare("CALL `cwhpanel_admin`.`{$proc}`({$ph})");
        $stmt->execute($args);
        $stmt->closeCursor();
    }

    private function callProcRows(string $proc, array $args = []): array
    {
        $ph = $args === [] ? '' : implode(',', array_fill(0, count($args), '?'));
        $stmt = $this->adminPdo->prepare("CALL `cwhpanel_admin`.`{$proc}`({$ph})");
        $stmt->execute($args);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    }

    public function limitsForAccount(int $accountId): array
    {
        $row = app('db')->selectOne(
            'SELECT p.`mysql_max_user_connections`, p.`mysql_max_queries_per_hour`,
                    p.`mysql_max_updates_per_hour`, p.`mysql_max_statement_sec`
             FROM `packages` p JOIN `accounts` a ON a.`package_id` = p.`id`
             WHERE a.`id` = ?',
            [$accountId]
        );
        $limits = self::DEFAULT_LIMITS;
        if (is_array($row)) {
            foreach (array_keys($limits) as $k) {
                if (isset($row[$k]) && is_numeric($row[$k])) {
                    $limits[$k] = max(0, (int) $row[$k]);
                }
            }
        }
        return $limits;
    }

    /**
     * Total bytes used by an account's databases (data + index), read from
     * information_schema. Used by the database storage quota.
     */
    public function accountDatabaseBytes(int $accountId): int
    {
        $names = app('db')->select('SELECT `name` FROM `hosting_databases` WHERE `account_id` = ?', [$accountId]);
        if ($names === []) {
            return 0;
        }
        $total = 0;
        $svc = app(DatabaseService::class);
        foreach ($names as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            try {
                $total += $svc->databaseBytes($name);
            } catch (\Throwable $e) {
                app('logger')->warning("Database size probe failed for {$name}: " . $e->getMessage());
            }
        }
        return $total;
    }

    /** Package database quota in MB (0 = unlimited). */
    public function databaseQuotaMb(int $accountId): int
    {
        try {
            return max(0, (int) app('db')->scalar(
                'SELECT p.`database_quota_mb` FROM `accounts` a JOIN `packages` p ON p.`id` = a.`package_id` WHERE a.`id` = ?',
                [$accountId]
            ));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Refuse database creation once the account's databases are at or above
     * its package quota. Reads keep working; this only blocks growth.
     */
    public function assertDatabaseQuota(int $accountId): void
    {
        $quotaMb = $this->databaseQuotaMb($accountId);
        if ($quotaMb <= 0) {
            return;
        }
        $used = $this->accountDatabaseBytes($accountId);
        if ($used >= $quotaMb * 1048576) {
            $usedMb = (int) round($used / 1048576);

            throw new \RuntimeException("Database storage quota reached ({$usedMb}/{$quotaMb} MB). Remove data or upgrade the package.");
        }
    }

    /**
     * Apply the owning account's caps to every login of one DB username.
     * Looks the account up from hosting_databases/hosting_db_users, so call
     * sites only need the username. Returns logins updated (0 = unknown user).
     */
    public function applyForUsername(string $username): int
    {
        $user = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
        if ($user === '') {
            return 0;
        }
        $accountId = $this->accountForUser($user);
        if ($accountId === null) {
            return 0;
        }
        return $this->applyLimits($user, $this->limitsForAccount($accountId));
    }

    /** Apply caps to every DB user of an account. Returns users updated. */
    public function applyAccountLimits(int $accountId): int
    {
        $limits = $this->limitsForAccount($accountId);
        $names = app('db')->select(
            'SELECT `username` FROM `hosting_databases` WHERE `account_id` = ?
             UNION SELECT `username` FROM `hosting_db_users` WHERE `account_id` = ?',
            [$accountId, $accountId]
        );
        $n = 0;
        foreach ($names as $row) {
            $user = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($row['username'] ?? '')));
            if ($user === '') {
                continue;
            }
            try {
                $this->applyLimits($user, $limits);
                $n++;
            } catch (\Throwable $e) {
                app('logger')->warning("MySQL governor apply failed for {$user}: " . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * One killer sweep. Returns ['checked'=>int, 'killed'=>int, 'top'=>string].
     * Never throws for kill races (a finished thread is simply skipped).
     */
    public function sweep(): array
    {
        $self = (int) $this->adminPdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $map = $this->managedUsers();
        $procs = $this->adminPdo->query(
            'SELECT `ID`, `USER`, `DB`, `COMMAND`, `TIME`, LEFT(`INFO`, 200) AS `query_prefix`
             FROM `INFORMATION_SCHEMA`.`PROCESSLIST`'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $checked = 0;
        $killed = 0;
        $top = '';
        foreach ($procs ?: [] as $p) {
            $id = (int) ($p['ID'] ?? 0);
            if ($id === 0 || $id === $self) {
                continue;
            }
            $user = strtolower((string) ($p['USER'] ?? ''));
            if (!isset($map[$user])) {
                continue; // system users, panel login, foreigners: hands off.
            }
            if (!in_array($p['COMMAND'] ?? '', ['Query', 'Execute'], true)) {
                continue; // Sleep/idle, Binlog Dump, Connect: never killed.
            }
            $checked++;
            $raw = (int) $map[$user]['limit'];
            if ($raw === 0) {
                continue; // unlimited for this account.
            }
            $limit = max(self::MIN_STATEMENT_SEC, $raw);
            if ((int) ($p['TIME'] ?? 0) <= $limit) {
                continue;
            }
            try {
                // KILL QUERY of another user's thread needs SUPER/CONNECTION
                // ADMIN, which the scoped panel account does not hold; the
                // DEFINER procedure performs it as root instead.
                $this->callProc('cwh_kill_query', [$id]);
            } catch (\Throwable $e) {
                continue; // thread finished between list and kill: fine.
            }
            $killed++;
            $top = "{$user} ({$p['TIME']}s)";
            try {
                app('db')->insert('mysql_governor_events', [
                    'account_id' => (int) $map[$user]['account_id'],
                    'db_user' => $user,
                    'mysql_id' => $id,
                    'db' => (string) ($p['DB'] ?? ''),
                    'query_prefix' => (string) ($p['query_prefix'] ?? ''),
                    'time_sec' => (int) ($p['TIME'] ?? 0),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                app('logger')->warning('MySQL governor event log failed: ' . $e->getMessage());
            }
        }
        return ['checked' => $checked, 'killed' => $killed, 'top' => $top];
    }

    /** Recent kills for the admin watchdog page. */
    public function recentEvents(int $limit = 20): array
    {
        return app('db')->select(
            'SELECT e.*, a.`username` AS `account_username` FROM `mysql_governor_events` e
             LEFT JOIN `accounts` a ON a.`id` = e.`account_id`
             ORDER BY e.`id` DESC LIMIT ' . max(1, $limit)
        );
    }

    // ------------------------------------------------------------ internals

    /** mysql username => [account_id, limit]; from both panel user tables. */
    private function managedUsers(): array
    {
        $map = [];
        $rows = app('db')->select(
            'SELECT d.`username`, d.`account_id`, p.`mysql_max_statement_sec`
             FROM `hosting_databases` d
             LEFT JOIN `accounts` a ON a.`id` = d.`account_id`
             LEFT JOIN `packages` p ON p.`id` = a.`package_id`
             UNION
             SELECT u.`username`, u.`account_id`, p.`mysql_max_statement_sec`
             FROM `hosting_db_users` u
             LEFT JOIN `accounts` a ON a.`id` = u.`account_id`
             LEFT JOIN `packages` p ON p.`id` = a.`package_id`'
        );
        foreach ($rows as $r) {
            $user = strtolower((string) ($r['username'] ?? ''));
            if ($user === '' || isset($map[$user])) {
                continue;
            }
            $limit = isset($r['mysql_max_statement_sec']) && is_numeric($r['mysql_max_statement_sec'])
                ? (int) $r['mysql_max_statement_sec']
                : self::DEFAULT_LIMITS['mysql_max_statement_sec'];
            $map[$user] = ['account_id' => (int) ($r['account_id'] ?? 0), 'limit' => $limit];
        }
        return $map;
    }

    private function accountForUser(string $user): ?int
    {
        $row = app('db')->selectOne(
            'SELECT `account_id` FROM `hosting_databases` WHERE `username` = ?
             UNION SELECT `account_id` FROM `hosting_db_users` WHERE `username` = ? LIMIT 1',
            [$user, $user]
        );
        return $row ? (int) $row['account_id'] : null;
    }

    /** All 'user'@'host' logins MySQL actually has for a username. */
    private function loginHosts(string $user): array
    {
        $rows = $this->callProcRows('cwh_user_hosts', [$user]);
        $hosts = ['localhost'];
        foreach ($rows as $r) {
            $h = (string) ($r['Host'] ?? '');
            if ($h !== '' && $h !== 'localhost' && preg_match('/^[a-z0-9.%_-]+$/i', $h)) {
                $hosts[] = $h;
            }
        }
        return array_values(array_unique($hosts));
    }

    private function applyLimits(string $user, array $limits): int
    {
        $c = max(0, (int) ($limits['mysql_max_user_connections'] ?? 0));
        $q = max(0, (int) ($limits['mysql_max_queries_per_hour'] ?? 0));
        $u = max(0, (int) ($limits['mysql_max_updates_per_hour'] ?? 0));
        $n = 0;
        foreach ($this->loginHosts($user) as $host) {
            $this->callProc('cwh_user_limits', [$user, $host, $c, $q, $u]);
            $n++;
        }
        return $n;
    }
}
