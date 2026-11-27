<?php
declare(strict_types=1);
namespace Modules\Backups\Services;

/**
 * Creates, restores, and deletes account backups via the agent.
 *
 * Backups are tar.gz archives of the account home directory plus per-database
 * mysqldump files, stored under /var/backups.
 */
class BackupService
{
    private const BACKUP_DIR = '/var/backups';

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'files' => 'Files only (account home)',
            'databases' => 'Databases only (mysqldump)',
            'full' => 'Files + databases',
            default => 'Unknown scope (recorded type: ' . $type . ')',
        };
    }

    /**
     * Strip the literal `.tar` suffix from a stored backup name to get the
     * archive base used for agent operations. (rtrim($name, '.tar') is wrong
     * here — it strips any trailing `.`/`t`/`a`/`r` characters, corrupting
     * names that happen to end in those letters.)
     */
    public static function nameBase(string $name): string
    {
        return str_ends_with($name, '.tar') ? substr($name, 0, -4) : $name;
    }

    private function account(int $accountId): ?array
    {
        return app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
    }

    /** Bytes currently held by an account's completed local artifacts. */
    public function storedBytes(int $accountId): int
    {
        $total = 0;
        $rows = app('db')->select(
            "SELECT * FROM `backups` WHERE `account_id` = ? AND `status` = 'completed'",
            [$accountId]
        );
        foreach ($rows as $row) {
            foreach ($this->artifacts($row) as $artifact) {
                $total += (int) ($artifact['size'] ?? 0);
            }
        }

        return $total;
    }

    /** Package backup quota in MB (0 = unlimited). */
    public function backupQuotaMb(int $accountId): int
    {
        try {
            return max(0, (int) app('db')->scalar(
                'SELECT p.`backup_quota_mb` FROM `accounts` a JOIN `packages` p ON p.`id` = a.`package_id` WHERE a.`id` = ?',
                [$accountId]
            ));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Refuse a new backup while the account is at/above its backup quota.
     * Existing artifacts are never deleted here — retention rules own that.
     */
    public function assertBackupQuota(int $accountId): void
    {
        $quotaMb = $this->backupQuotaMb($accountId);
        if ($quotaMb <= 0) {
            return;
        }
        $used = $this->storedBytes($accountId);
        if ($used >= $quotaMb * 1048576) {
            $usedGb = round($used / 1073741824, 1);

            throw new \RuntimeException("Backup quota reached ({$usedGb} GB of {$quotaMb} MB). Delete old backups or upgrade the package.");
        }
    }

    public function create(int $accountId, string $name): bool
    {
        if ($this->account($accountId) === null) {
            return false;
        }
        \Modules\Packages\Services\LimitService::requireHardCap($accountId, 'backups');
        // Route through createStatusWith so the `.php` ini-symlink exclusion
        // (which keeps archives RESTORABLE) is applied here too. The direct
        // agent call used to bypass it and produced archives whose restore
        // was refused by the escape-symlink pre-scan.
        return $this->createStatusWith($accountId, $name) === 'success';
    }

    /** Owning account for a database name (toggle lookup for dumps). */
    private function accountIdForDatabase(string $database): int
    {
        $row = app('db')->selectOne(
            'SELECT `account_id` FROM `hosting_databases` WHERE `name` = ?',
            [preg_replace('/[^a-z0-9_]/', '', strtolower($database))]
        );
        return $row !== null ? (int) $row['account_id'] : 0;
    }

    /** Seal an artifact in place when the account encrypts backups. */
    private function sealIfEnabled(int $accountId, string $path): void
    {
        try {
            $row = $this->account($accountId);
            if ($row === null || empty($row['backup_encrypted'])) {
                return;
            }
            if (!is_file($path) || BackupCrypto::isEncrypted($path)) {
                return;
            }
            $tmp = $path . '.seal-' . bin2hex(random_bytes(4));
            try {
                BackupCrypto::sealFile($path, $tmp);
                rename($tmp, $path);
                audit('backup.sealed', 'backup', $path);
            } catch (\Throwable $e) {
                @unlink($tmp);
                app('logger')->warning('Backup seal failed for ' . $path . ': ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            @unlink($path . '.seal-' . ($tmp ?? ''));
            app('logger')->warning('Backup seal failed for ' . $path . ': ' . $e->getMessage());
        }
    }

    /**
     * Decrypt an artifact for restore ops (which resolve names inside
     * BACKUP_DIR): returns a temp base name, or $name untouched when the
     * artifact is plaintext. Caller unlinks BACKUP_DIR/<tmp>.tar.gz (.sql).
     */
    private function openForRestore(string $dir, string $name, string $suffix): string
    {
        $path = $dir . '/' . $name . $suffix;
        if (!BackupCrypto::isEncrypted($path)) {
            return $name;
        }
        $tmpBase = $name . '.open-' . bin2hex(random_bytes(4));
        BackupCrypto::openFile($path, $dir . '/' . $tmpBase . $suffix);
        return $tmpBase;
    }

    /**
     * Decrypt an artifact for download, preserving the original filename:
     * output goes to a unique temp dir (removed at shutdown).
     */
    private function openForDownload(string $real): string
    {
        if (!BackupCrypto::isEncrypted($real)) {
            return $real;
        }
        $dir = sys_get_temp_dir() . '/cwhdl-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        BackupCrypto::openFile($real, $dir . '/' . basename($real));
        register_shutdown_function(function () use ($dir, $real) {
            @unlink($dir . '/' . basename($real));
            @rmdir($dir);
        });
        return $dir . '/' . basename($real);
    }

    /**
     * Run the backup and return the raw provisioning status string
     * ('success', 'dry-run', or an error). Used to report the exact outcome
     * instead of collapsing dry-run (provisioning disabled) into failure.
     */
    public function createStatus(int $accountId, string $name): string
    {
        return $this->createStatusWith($accountId, $name);
    }

    /**
     * Archive the account home (optionally only a relative subpath like
     * "mail", or excluding relative paths like "mail").
     *
     * @param array<int,string> $excludes
     */
    public function createStatusWith(int $accountId, string $name, ?string $only = null, array $excludes = []): string
    {
        $row = $this->account($accountId);
        if ($row === null) {
            return 'error';
        }
        $this->assertBackupQuota($accountId);

        $payload = [
            'home' => $row['home_dir'] ?? '/home/' . $row['username'],
            'name' => $name,
            'backup_dir' => self::BACKUP_DIR,
        ];
        if ($only !== null) {
            $payload['only'] = $only;
        } else {
            // `.php` holds the per-account ini scan dir: generated symlinks to
            // /etc/php.d (and master configs). Archiving them makes the
            // archive UNRESTORABLE (the agent's escape-symlink pre-scan
            // refuses absolute links out of the home). Exclude by default.
            $excludes = array_values(array_unique(array_merge(['.php'], $excludes)));
            $payload['excludes'] = implode(',', $excludes);
        }

        $result = app('provisioning')->execute('backup.create', $payload);

        $status = (string) ($result['status'] ?? 'error');
        if ($status === 'success') {
            $this->sealIfEnabled($accountId, self::BACKUP_DIR . '/' . $name . '.tar.gz');
        }
        return $status;
    }

    /**
     * Export every DNS zone owned by the account into one text artifact
     * (/var/backups/<name>.dns.txt). Returns a provisioning status string.
     */
    public function exportDns(int $accountId, string $name): string
    {
        if (!module_enabled('dns')) {
            return 'skipped';
        }
        try {
            $zones = app('db')->select('SELECT `name` FROM `dns_zones` WHERE `account_id` = ? ORDER BY `name`', [$accountId]);
            if ($zones === []) {
                return 'skipped';
            }
            $svc = app(\Modules\Dns\Services\DnsService::class);
            $content = '';
            $exported = 0;
            foreach ($zones as $z) {
                try {
                    $content .= "; zone: {$z['name']}\n" . $svc->listZone((string) $z['name']) . "\n";
                    $exported++;
                } catch (\Throwable $e) {
                    // Stale dns_zones row (zone deleted out of band) must not
                    // abort the whole export — skip and keep going.
                    app('logger')->warning('DNS export skipped zone ' . $z['name'] . ': ' . $e->getMessage());
                }
            }
            if ($exported === 0) {
                return 'skipped';
            }
            $result = app('provisioning')->execute('backup.dns.write', [
                'name' => $name,
                'content' => $content,
            ]);
            return (string) ($result['status'] ?? 'error');
        } catch (\Throwable $e) {
            app('logger')->warning('DNS export failed for account ' . $accountId . ': ' . $e->getMessage());
            return 'error';
        }
    }

    public function restore(int $accountId, string $name): bool
    {
        $row = $this->account($accountId);
        if ($row === null) {
            return false;
        }

        $useName = $name;
        $tmp = null;
        try {
            $useName = $this->openForRestore(self::BACKUP_DIR, $name, '.tar.gz');
            $tmp = $useName !== $name ? self::BACKUP_DIR . '/' . $useName . '.tar.gz' : null;
            $result = app('provisioning')->execute('backup.restore', [
                'account' => $row['username'],
                'home' => $row['home_dir'] ?? '/home/' . $row['username'],
                'name' => $useName,
                'backup_dir' => self::BACKUP_DIR,
            ]);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }

        if ($result['status'] !== 'success') {
            return false;
        }

        // Re-establish the account's mail state after the files come back:
        // Maildir content is inside the archive (mail/ under the home tree),
        // but ensure every active mailbox has its cur/new/tmp dirs with correct
        // ownership so Dovecot can deliver/read immediately after a restore.
        $this->ensureMaildirs($row);

        // Archive extraction may preserve/restore labels whose types the web
        // stack cannot read (e.g. user_home_dir_t after a `restorecon`-style
        // restore). Re-assert the account-content type on the site docroots —
        // otherwise sites come back as nginx 403s. Best-effort: a failure is
        // logged, never fatal to the restore (the type sweep covers leftovers).
        try {
            app('provisioning')->execute('selinux.content.fix', [
                'account' => $row['username'],
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('Post-restore SELinux content relabel failed for ' . $row['username'] . ': ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Idempotent per-mailbox Maildir ensure (mkdir -p + chown via the agent).
     * Failures are logged, never fatal to the restore itself.
     */
    private function ensureMaildirs(array $account): void
    {
        try {
            $boxes = app('db')->select(
                'SELECT `email` FROM `mailboxes` WHERE `account_id` = ? AND `status` = ?',
                [(int) $account['id'], 'active']
            );
        } catch (\Throwable $e) {
            return;
        }

        foreach ($boxes as $b) {
            $local = strtolower(explode('@', (string) ($b['email'] ?? ''), 2)[0] ?? '');
            if ($local === '') {
                continue;
            }
            try {
                app('provisioning')->execute('mail.maildir.create', [
                    'account' => $account['username'],
                    'mailbox' => $local,
                ]);
            } catch (\Throwable $e) {
                app('logger')->warning('Post-restore maildir ensure failed for ' . $local . ': ' . $e->getMessage());
            }
        }
    }

    public function delete(string $name): bool
    {
        $result = app('provisioning')->execute('backup.delete', [
            'name' => $name,
            'backup_dir' => self::BACKUP_DIR,
        ]);

        return $result['status'] === 'success';
    }

    /**
     * Dump a single database via mysqldump (root socket auth in the agent).
     * Returns 'success', 'dry-run', or 'error' to mirror createStatus().
     */
    public function dbCreate(string $database, string $name): string
    {
        $result = app('provisioning')->execute('backup.db.create', [
            'database' => $database,
            'name' => $name,
            'backup_dir' => self::BACKUP_DIR,
        ]);

        $status = (string) ($result['status'] ?? 'error');
        if ($status === 'success') {
            // Dumps land as {name}.sql next to the tarballs.
            $this->sealIfEnabled($this->accountIdForDatabase($database), self::BACKUP_DIR . '/' . $name . '.sql');
        }
        return $status;
    }

    public function dbRestore(string $database, string $name): bool
    {
        $useName = $name;
        $tmp = null;
        try {
            $useName = $this->openForRestore(self::BACKUP_DIR, $name, '.sql');
            $tmp = $useName !== $name ? self::BACKUP_DIR . '/' . $useName . '.sql' : null;
            // Fail-closed twin of the agent's dump validation: a customer S3
            // remote can supply any SQL, and the import runs as the root DB
            // user — so refuse privilege-management / cross-schema / client-
            // command content before the agent ever sees it.
            self::assertSafeSqlDump(self::BACKUP_DIR . '/' . $useName . '.sql', $database);
            $result = app('provisioning')->execute('backup.db.restore', [
                'database' => $database,
                'name' => $useName,
                'backup_dir' => self::BACKUP_DIR,
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('DB restore refused: ' . $e->getMessage());
            return false;
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }

        return $result['status'] === 'success';
    }

    /**
     * Fail-closed client-side twin of the agent's validate_sql_dump: refuse
     * dumps that manage accounts/privileges, switch schemas, use client-only
     * commands, or touch system schemas / server-side files. See the agent
     * implementation (agent/src/ops.rs) for the rationale and limitations.
     */
    public static function assertSafeSqlDump(string $path, string $expectedDb): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException('SQL dump not found for restore validation.');
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('Cannot open SQL dump for restore validation.');
        }

        $stmt = '';
        $trunc = false;
        $no = 0;
        $sq = $dq = $bt = $lc = $bc = false;
        $prev = '';
        $bad = null;

        while (($line = fgets($h, 65536)) !== false) {
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if (($sq || $dq || $bt) && $ch === '\\') {
                    continue;
                }
                if ($lc) {
                    if ($ch === "\n") { $lc = false; }
                    continue;
                }
                if ($bc) {
                    if ($ch === '/' && $prev === '*') { $bc = false; }
                    $prev = $ch;
                    continue;
                }
                if ($sq) {
                    if ($ch === "'") { $sq = false; }
                    $prev = $ch;
                    continue;
                }
                if ($dq) {
                    if ($ch === '"') { $dq = false; }
                    $prev = $ch;
                    continue;
                }
                if ($bt) {
                    if ($ch === '`') { $bt = false; }
                    else {
                        // Backtick content is an IDENTIFIER (not a literal):
                        // keep it in the statement buffer so `USE \`db\`` /
                        // `CREATE TABLE \`t\`` classify correctly (mirror of
                        // the agent's validate_sql_dump behavior).
                        if (strlen($stmt) < 4096) {
                            $stmt .= $ch;
                        } elseif (strlen($stmt) === 4096) {
                            $trunc = true;
                        }
                    }
                    $prev = $ch;
                    continue;
                }
                if ($ch === "'") { $sq = true; $prev = $ch; continue; }
                if ($ch === '"') { $dq = true; $prev = $ch; continue; }
                if ($ch === '`') { $bt = true; $prev = $ch; continue; }
                if ($ch === '#') { $lc = true; $prev = $ch; continue; }
                if ($ch === '-' && $prev === '-') { $lc = true; $prev = ''; continue; }
                if ($ch === '*' && $prev === '/') { $bc = true; $prev = ''; continue; }
                if ($ch === ';') {
                    $no++;
                    if (($v = self::classifySqlStatement($stmt, $trunc, $no, $expectedDb)) !== null) {
                        $bad = $v;
                        break 2;
                    }
                    $stmt = '';
                    $trunc = false;
                    $prev = $ch;
                    continue;
                }
                if ($ch === '!' && $prev === '*' && substr($stmt, -2) === '/*') {
                    $bc = false;
                }
                if (strlen($stmt) < 4096) {
                    $stmt .= $ch;
                } elseif (strlen($stmt) === 4096) {
                    $trunc = true;
                }
                $prev = $ch;
            }
        }
        fclose($h);

        if ($bad === null) {
            $no++;
            $bad = self::classifySqlStatement($stmt, $trunc, $no, $expectedDb);
        }
        if ($bad !== null) {
            throw new \RuntimeException("Unsafe SQL dump: {$bad}");
        }
    }

    private static function classifySqlStatement(string $stmt, bool $trunc, int $no, string $expectedDb): ?string
    {
        $s = trim($stmt);
        if ($s === '') {
            return null;
        }
        if (strncmp($s, '/*!', 3) === 0) {
            $s = substr($s, 3);
            $s = ltrim($s, '0123456789');
        }
        $lower = strtolower($s);
        $t = ltrim($lower);
        if (str_starts_with($t, '\\') || str_starts_with($t, 'system ') || str_starts_with($t, 'source ')
            || $t === 'system' || $t === 'source' || str_starts_with($t, 'delimiter ')
        ) {
            return "statement {$no} uses a client-only command";
        }

        preg_match_all('/[a-z0-9_`]+/', $lower, $m);
        $tokens = array_values(array_filter($m[0], fn ($x) => $x !== ''));
        if ($tokens === []) {
            return null;
        }
        $a = $tokens[0];
        $b = $tokens[1] ?? '';

        if (in_array($a, ['grant', 'revoke', 'install', 'uninstall', 'flush', 'reset'], true)) {
            return "statement {$no} modifies server privileges/state";
        }
        if ((in_array($a, ['create', 'alter', 'drop', 'rename'], true) && $b === 'user')
            || ($a === 'create' && $b === 'role')
            || ($a === 'drop' && $b === 'role')
            || ($a === 'set' && $b === 'role')
            || ($a === 'set' && $b === 'password')
        ) {
            return "statement {$no} manages accounts/roles";
        }
        if ($a === 'use' && !self::dbNameMatches($b, $expectedDb)) {
            return "statement {$no} switches to another database";
        }
        if (($a === 'create' || $a === 'drop') && $b === 'database') {
            $c = $tokens[2] ?? '';
            if (!self::dbNameMatches($c, $expectedDb)) {
                return "statement {$no} affects another database";
            }
        }
        if (str_contains($lower, 'mysql.') || str_contains($lower, 'information_schema.')
            || str_contains($lower, 'performance_schema.') || str_contains($lower, 'sys.')
        ) {
            return "statement {$no} references a system schema";
        }
        if ($a === 'select') {
            $prevInto = false;
            foreach (array_slice($tokens, 1) as $tk) {
                if ($prevInto && in_array($tk, ['outfile', 'dumpfile'], true)) {
                    return "statement {$no} writes server-side files";
                }
                $prevInto = $tk === 'into';
                if ($tk === 'load_file') {
                    return "statement {$no} reads server-side files";
                }
            }
        }
        if ($trunc) {
            return "statement {$no} exceeds the scan limit";
        }
        return null;
    }

    private static function dbNameMatches(string $tok, string $expected): bool
    {
        $t = trim($tok, "`'\"");
        $base = explode('.', $t)[0];
        return strcasecmp($base, $expected) === 0;
    }

    public function dbDelete(string $name): bool
    {
        // DB dumps are `{name}.sql`, not `{name}.tar.gz` — they need their own
        // delete op (backup.delete targets the tarball and would rm -f a
        // nonexistent file while the dump accumulates forever).
        $result = app('provisioning')->execute('backup.db.delete', [
            'name' => $name,
            'backup_dir' => self::BACKUP_DIR,
        ]);

        return $result['status'] === 'success';
    }

    /**
     * Downloadable artifacts for a backup row: the tarball (files/full types)
     * plus each per-database dump (databases/full types). Only files that
     * actually exist on disk are listed.
     *
     * @return array<int, array{file:string, size:int}>
     */
    public function artifacts(array $backup): array
    {
        $base = self::nameBase((string) ($backup['name'] ?? ''));
        if ($base === '' || str_contains($base, '/')) {
            return [];
        }

        $candidates = [];
        if (in_array($backup['type'] ?? '', ['files', 'full'], true)) {
            $candidates[] = $base . '.tar.gz';
        }
        if (in_array($backup['type'] ?? '', ['databases', 'full'], true)) {
            foreach ($this->accountDatabases((int) ($backup['account_id'] ?? 0)) as $dbName) {
                $candidates[] = $base . '.db.' . $dbName . '.sql';
            }
        }

        $out = [];
        $safeSize = static function (string $path): ?int {
            // Defensive: outside open_basedir is_file() raises a warning that
            // the panel's error handler escalates to a 500. Suppress and treat
            // unreadable/absent as "not listed".
            if (!@is_file($path)) {
                return null;
            }
            $size = @filesize($path);

            return $size === false ? null : (int) $size;
        };
        foreach ($candidates as $file) {
            $size = $safeSize(self::BACKUP_DIR . '/' . $file);
            if ($size !== null) {
                $out[] = ['file' => $file, 'size' => $size];
            }
        }

        // Fleet-plan DNS export (one text file per backup set).
        $dnsFile = $base . '.dns.txt';
        $dnsSize = $safeSize(self::BACKUP_DIR . '/' . $dnsFile);
        if ($dnsSize !== null) {
            $out[] = ['file' => $dnsFile, 'size' => $dnsSize];
        }

        return $out;
    }

    /**
     * Resolve a validated on-disk path for downloading one artifact of a
     * backup row. Returns null when the row, ownership, filename, or file is
     * invalid — never a path outside the backup dir (realpath-confined).
     */
    public function downloadPath(int $accountId, int $backupId, ?string $file): ?string
    {
        $backup = app('db')->selectOne(
            'SELECT * FROM `backups` WHERE `id` = ? AND `account_id` = ?',
            [$backupId, $accountId]
        );

        if ($backup === null || ($backup['status'] ?? '') !== 'completed') {
            return null;
        }

        $allowed = array_column($this->artifacts($backup), 'file');
        if ($file === null || $file === '') {
            $file = $allowed[0] ?? null;
        }
        if ($file === null || !in_array($file, $allowed, true)) {
            return null;
        }

        $real = @realpath(self::BACKUP_DIR . '/' . $file);
        if ($real === false || !str_starts_with($real, self::BACKUP_DIR . '/') || !@is_file($real)) {
            return null;
        }

        try {
            return $this->openForDownload($real);
        } catch (\Throwable $e) {
            app('logger')->warning('Backup download decrypt failed for ' . $file . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The database names owned by an account (from hosting_databases), sanitized
     * to the same conservative set the agent accepts.
     */
    public function accountDatabases(int $accountId): array
    {
        $rows = app('db')->select(
            'SELECT `name` FROM `hosting_databases` WHERE `account_id` = ? ORDER BY `name`',
            [$accountId]
        );

        return array_values(array_filter(array_map(
            fn ($r) => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($r['name'] ?? ''))),
            array_filter($rows)
        )));
    }

    // ------------------------------------------------- scheduled + offsite

    public const FREQUENCIES = ['daily' => 86400, 'weekly' => 604800];
    public const TYPES = ['full', 'files', 'databases'];

    /** Fleet-plan components and their labels (admin UI). */
    public const COMPONENTS = [
        'files' => 'Files (account home, excluding mail)',
        'databases' => 'Databases (mysqldump per database)',
        'mail' => 'Email (mailboxes / Maildir)',
        'dns' => 'DNS zones (PowerDNS export)',
    ];

    /** The single fleet plan row (created on first visit), or null. */
    public function plan(): ?array
    {
        try {
            return app('db')->selectOne('SELECT * FROM `backup_plans` ORDER BY `id` LIMIT 1');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int,string> enabled components of a plan row */
    public static function planComponents(?array $plan): array
    {
        $raw = array_filter(array_map('trim', explode(',', (string) ($plan['components'] ?? ''))));
        $out = array_values(array_intersect(array_keys(self::COMPONENTS), $raw));

        return $out !== [] ? $out : ['files', 'databases'];
    }

    public static function nextRun(string $frequency, int $fromTs): int
    {
        return $fromTs + (self::FREQUENCIES[$frequency] ?? self::FREQUENCIES['daily']);
    }

    /**
     * The exact create logic behind both manual (controller store) and
     * scheduled backups. Returns [fileStatus, dbStatus] with the raw
     * provisioning statuses ('success', 'dry-run', 'error').
     */
    public function createSet(int $accountId, string $nameBase, string $type, ?array $components = null): array
    {
        // Legacy callers pass a type; the fleet plan passes components. The
        // legacy home archive implicitly contains mail, so it stays unchanged.
        $components = $components ?? ['files', 'databases'];

        $fileStatus = 'skipped';
        $dbStatus = 'skipped';
        $dnsStatus = 'skipped';

        $files = in_array('files', $components, true);
        $mail = in_array('mail', $components, true);

        if ($files && $mail) {
            $fileStatus = $this->createStatus($accountId, $nameBase);
        } elseif ($mail) {
            $fileStatus = $this->createStatusWith($accountId, $nameBase, 'mail');
        } elseif ($files) {
            $fileStatus = $this->createStatusWith($accountId, $nameBase, null, ['mail']);
        }

        if (in_array('databases', $components, true)) {
            foreach ($this->accountDatabases($accountId) as $dbName) {
                $s = $this->dbCreate($dbName, $nameBase . '.db.' . $dbName);
                if ($s !== 'success') {
                    $dbStatus = $s;
                }
            }
        }

        if (in_array('dns', $components, true)) {
            $dnsStatus = $this->exportDns($accountId, $nameBase);
        }

        return [$fileStatus, $dbStatus, $dnsStatus];
    }

    /**
     * Persist the fleet plan (single row). Returns the plan id.
     *
     * @param array<string,mixed> $input enabled, frequency, components[], keep_local
     */
    public function savePlan(array $input): int
    {
        $db = app('db');
        $frequency = isset(self::FREQUENCIES[(string) ($input['frequency'] ?? '')])
            ? (string) $input['frequency'] : 'daily';
        $components = array_values(array_intersect(
            array_keys(self::COMPONENTS),
            array_map('strval', (array) ($input['components'] ?? []))
        ));
        if ($components === []) {
            throw new \InvalidArgumentException('Select at least one backup component.');
        }

        $enabled = (int) ($input['enabled'] ?? 0) === 1;
        $row = [
            'enabled' => $enabled ? 1 : 0,
            'frequency' => $frequency,
            'components' => implode(',', $components),
            'keep_local' => max(1, min(365, (int) ($input['keep_local'] ?? 7))),
            'keep_remote' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $existing = $this->plan();
        if ($existing === null) {
            $row['last_run_at'] = 0;
            $row['next_run_at'] = $enabled ? self::nextRun($frequency, time()) : 0;
            $row['created_at'] = date('Y-m-d H:i:s');
            $id = (int) $db->insert('backup_plans', $row);
        } else {
            $id = (int) $existing['id'];
            if ($enabled && (int) ($existing['next_run_at'] ?? 0) === 0) {
                $row['next_run_at'] = self::nextRun($frequency, time());
            }
            if (!$enabled) {
                $row['next_run_at'] = 0;
            }
            $db->update('backup_plans', $row, '`id` = ?', [$id]);
        }

        audit('backup.plan.saved', 'backup_plan', $id, [
            'enabled' => $enabled,
            'frequency' => $frequency,
            'components' => $components,
        ]);

        return $id;
    }

    /** True when the plan is enabled and its next_run_at has passed. */
    public static function planDue(?array $plan, int $now): bool
    {
        if ($plan === null || empty($plan['enabled'])) {
            return false;
        }
        $next = (int) ($plan['next_run_at'] ?? 0);

        return $next === 0 || $next <= $now;
    }

    public function registerBackup(int $accountId, string $nameBase, string $type, bool $ok, bool $dryRun): int
    {
        \Modules\Packages\Services\LimitService::requireHardCap($accountId, 'backups');
        $id = (int) app('db')->insert('backups', [
            'account_id' => $accountId,
            'name' => $nameBase . '.tar',
            'type' => $type,
            'size_bytes' => 0,
            'status' => $ok ? 'completed' : ($dryRun ? 'dry-run' : 'failed'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            event('BackupCreated', ['backup_id' => $id, 'account_id' => $accountId]);
            audit('backup.scheduled', 'backup', $id);
        } catch (\Throwable $e) {
            // Observability must never fail the backup itself.
        }
        return $id;
    }

    /**
     * Run one schedule end to end: create → upload → prune → reschedule.
     * Never throws for backup-level failures (they land in last_status);
     * returns a human summary for the job result.
     */
    public function runScheduled(int $scheduleId): string
    {
        $sched = app('db')->selectOne('SELECT * FROM `backup_schedules` WHERE `id` = ?', [$scheduleId]);
        if ($sched === null) {
            return 'schedule gone, nothing to do';
        }
        if (empty($sched['enabled'])) {
            return 'schedule disabled, skipped';
        }
        $accountId = (int) $sched['account_id'];
        $accountRow = $this->account($accountId);
        if ($accountRow === null) {
            $this->scheduleDone((int) $sched['id'], 'failed', 'account gone');
            return 'account gone, marked failed';
        }
        $type = in_array($sched['type'] ?? '', self::TYPES, true) ? $sched['type'] : 'full';
        // Account-scoped name: cheap attribution and collision-proofing in the
        // shared /var/backups directory (file-manager archives are per-account).
        $nameBase = 'backup-' . preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $accountRow['username'])) . '-' . date('Ymd-His');

        try {
            [$fileStatus, $dbStatus, $dnsStatus] = $this->createSet($accountId, $nameBase, $type);
        } catch (\Throwable $e) {
            $this->scheduleDone((int) $sched['id'], 'failed', mb_substr($e->getMessage(), 0, 400));
            return 'create failed: ' . $e->getMessage();
        }
        $bad = static fn (string $s): bool => in_array($s, ['error', 'failed'], true);
        $ok = !$bad($fileStatus) && !$bad($dbStatus) && !$bad($dnsStatus);
        $dryRun = $fileStatus === 'dry-run' || $dbStatus === 'dry-run' || $dnsStatus === 'dry-run';
        $backupId = $this->registerBackup($accountId, $nameBase, $type, $ok, $dryRun);

        $notes = [];
        if ($ok && !empty($sched['remote_id'])) {
            try {
                $n = $this->uploadBackup($accountId, $backupId, (int) $sched['remote_id']);
                $notes[] = "uploaded {$n} artifact(s)";
            } catch (\Throwable $e) {
                $notes[] = 'upload failed: ' . mb_substr($e->getMessage(), 0, 200);
                $ok = false;
            }
        }
        // Retention always runs (even on failure — partial sets still age).
        try {
            $notes[] = $this->pruneLocal($accountId, max(1, (int) ($sched['keep_local'] ?? 7))) . ' local pruned';
        } catch (\Throwable $e) {
            $notes[] = 'local prune failed: ' . mb_substr($e->getMessage(), 0, 200);
        }
        if (!empty($sched['remote_id'])) {
            try {
                $notes[] = $this->pruneRemote((int) $sched['remote_id'], max(0, (int) ($sched['keep_remote'] ?? 14))) . ' remote pruned';
            } catch (\Throwable $e) {
                $notes[] = 'remote prune failed: ' . mb_substr($e->getMessage(), 0, 200);
            }
        }

        $this->scheduleDone((int) $sched['id'], $ok ? 'completed' : 'failed', implode('; ', $notes));
        return ($ok ? 'completed' : 'failed') . ': ' . implode('; ', $notes);
    }

    /**
     * Execute the fleet plan: one backup set per active account (chosen
     * components) + retention. Per-account failures are collected, never fatal.
     */
    public function runPlan(int $planId): string
    {
        $db = app('db');
        $plan = $db->selectOne('SELECT * FROM `backup_plans` WHERE `id` = ?', [$planId]);
        if ($plan === null) {
            return 'plan gone, nothing to do';
        }
        if (empty($plan['enabled'])) {
            return 'plan disabled, skipped';
        }

        $components = self::planComponents($plan);
        $keep = max(1, (int) ($plan['keep_local'] ?? 7));
        $frequency = isset(self::FREQUENCIES[$plan['frequency'] ?? '']) ? (string) $plan['frequency'] : 'daily';
        $ok = 0;
        $failed = 0;
        $notes = [];

        foreach ($db->select("SELECT `id`,`username` FROM `accounts` WHERE `status` = 'active' ORDER BY `id`") as $acc) {
            $nameBase = 'plan-' . preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $acc['username'])) . '-' . date('Ymd-His');
            try {
                $note = $this->runPlanAccount((int) $acc['id'], $components, $keep, $nameBase);
                if ($note === '') {
                    $ok++;
                } else {
                    $failed++;
                    $notes[] = $acc['username'] . ': ' . $note;
                }
            } catch (\Throwable $e) {
                $failed++;
                $notes[] = $acc['username'] . ': ' . mb_substr($e->getMessage(), 0, 120);
            }
        }

        $status = $failed === 0 ? 'completed' : 'failed';
        $db->update('backup_plans', [
            'last_run_at' => time(),
            'next_run_at' => self::nextRun($frequency, time()),
            'last_status' => $status,
            'last_error' => mb_substr(implode('; ', $notes), 0, 500),
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$planId]);
        audit('backup.plan.ran', 'backup_plan', $planId, ['ok' => $ok, 'failed' => $failed]);

        return "{$status}: {$ok} ok, {$failed} failed" . ($notes !== [] ? ' — ' . implode('; ', $notes) : '');
    }

    /** One account inside a fleet-plan sweep. Returns '' on success, else a note. */
    public function runPlanAccount(int $accountId, array $components, int $keep, string $nameBase): string
    {
        // A quota-blocked scheduled run is skipped, never failed: the plan
        // keeps running and retries after retention/upgrade frees space.
        try {
            $this->assertBackupQuota($accountId);
        } catch (\Throwable $e) {
            return 'skipped: ' . $e->getMessage();
        }
        [$fs, $ds, $zs] = $this->createSet($accountId, $nameBase, 'full', $components);
        $bad = static fn (string $s): bool => in_array($s, ['error', 'failed'], true);
        $good = !$bad($fs) && !$bad($ds) && !$bad($zs);
        $this->registerBackup($accountId, $nameBase, 'full', $good, false);
        $this->pruneLocal($accountId, max(1, $keep));

        return $good ? '' : "files={$fs} db={$ds} dns={$zs}";
    }

    private function scheduleDone(int $scheduleId, string $status, string $note): void
    {
        $sched = app('db')->selectOne('SELECT * FROM `backup_schedules` WHERE `id` = ?', [$scheduleId]);
        if ($sched === null) {
            return;
        }
        $freq = isset(self::FREQUENCIES[$sched['frequency'] ?? '']) ? $sched['frequency'] : 'daily';
        app('db')->update('backup_schedules', [
            'last_run_at' => time(),
            'next_run_at' => self::nextRun($freq, time()),
            'last_status' => $status,
            'last_error' => mb_substr($note, 0, 500),
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$scheduleId]);
    }

    /**
     * Delete a backup row with all its local artifacts (mirrors the
     * controller destroy flow: tarball via backup.delete, dumps via
     * backup.db.delete, then the row + its copy ledger).
     */
    public function deleteBackupRow(array $backup): void
    {
        $base = self::nameBase((string) ($backup['name'] ?? ''));
        if ($base !== '' && !str_contains($base, '/')) {
            $this->delete($base);
            if (in_array($backup['type'] ?? '', ['databases', 'full'], true)) {
                foreach ($this->accountDatabases((int) ($backup['account_id'] ?? 0)) as $dbName) {
                    $this->dbDelete($base . '.db.' . $dbName);
                }
            }
        }
        app('db')->delete('backup_copies', '`backup_id` = ?', [(int) $backup['id']]);
        app('db')->delete('backups', '`id` = ?', [(int) $backup['id']]);
    }

    /**
     * Keep the newest $keep completed local backups for the account (by
     * sortable name desc). Returns the pruned count. Manual backups are
     * included — retention is per account, not per schedule, so one policy
     * cannot strand another's disk usage.
     */
    public function pruneLocal(int $accountId, int $keep): int
    {
        $rows = app('db')->select(
            "SELECT * FROM `backups` WHERE `account_id` = ? AND `status` = 'completed' ORDER BY `name` DESC",
            [$accountId]
        );
        $n = 0;
        foreach (array_slice($rows, max(0, $keep)) as $row) {
            $this->deleteBackupRow($row);
            $n++;
        }
        return $n;
    }

    /** Build an S3 client for a remote row (unseals the secret). */
    public function remoteClient(array $remote): S3Client
    {
        if (($remote['driver'] ?? '') !== 's3') {
            throw new \RuntimeException('Unknown remote driver.');
        }
        return new S3Client([
            'endpoint' => $remote['endpoint'],
            'bucket' => $remote['bucket'],
            'region' => $remote['region'] ?? 'us-east-1',
            'key' => $remote['access_key'],
            'secret' => RemoteSecret::open((string) $remote['secret_encrypted']),
        ]);
    }

    public function remotePrefix(array $remote, string $username): string
    {
        $prefix = trim((string) ($remote['path_prefix'] ?? ''), '/');
        $prefix = $prefix !== '' ? $prefix . '/' : '';
        return $prefix . $username . '/';
    }

    /**
     * Upload every local artifact of a backup row. Returns artifact count.
     * Records backup_copies rows (idempotent per path).
     */
    public function uploadBackup(int $accountId, int $backupId, int $remoteId): int
    {
        $backup = app('db')->selectOne(
            'SELECT * FROM `backups` WHERE `id` = ? AND `account_id` = ?',
            [$backupId, $accountId]
        );
        if ($backup === null || ($backup['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('Backup not available for upload.');
        }
        $remote = app('db')->selectOne(
            'SELECT * FROM `backup_remotes` WHERE `id` = ? AND `account_id` = ?',
            [$remoteId, $accountId]
        );
        if ($remote === null) {
            throw new \RuntimeException('Remote storage not found.');
        }
        $account = $this->account($accountId);
        $client = $this->remoteClient($remote);
        $prefix = $this->remotePrefix($remote, (string) ($account['username'] ?? 'unknown'));
        $n = 0;
        foreach ($this->artifacts($backup) as $a) {
            $local = self::BACKUP_DIR . '/' . $a['file'];
            $dest = $prefix . $a['file'];
            $client->put($local, $dest);
            $exists = app('db')->selectOne(
                'SELECT `id` FROM `backup_copies` WHERE `backup_id` = ? AND `remote_id` = ? AND `remote_path` = ?',
                [$backupId, $remoteId, $dest]
            );
            if ($exists === null) {
                app('db')->insert('backup_copies', [
                    'backup_id' => $backupId, 'remote_id' => $remoteId,
                    'remote_path' => $dest, 'size_bytes' => $a['size'],
                    'status' => 'completed',
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $n++;
        }
        if ($n === 0) {
            throw new \RuntimeException('No local artifacts to upload.');
        }
        return $n;
    }

    /**
     * Keep the newest $keep remote objects under the account prefix (keysort
     * desc = newest first thanks to the sortable Ymd-His names). Returns the
     * deleted count. keep=0 empties the prefix entirely.
     */
    public function pruneRemote(int $remoteId, int $keep): int
    {
        $remote = app('db')->selectOne('SELECT * FROM `backup_remotes` WHERE `id` = ?', [$remoteId]);
        if ($remote === null) {
            throw new \RuntimeException('Remote storage not found.');
        }
        $account = $this->account((int) $remote['account_id']);
        $client = $this->remoteClient($remote);
        $prefix = $this->remotePrefix($remote, (string) ($account['username'] ?? 'unknown'));
        $keys = $client->list($prefix, 10000);
        rsort($keys, SORT_STRING);
        $n = 0;
        foreach (array_slice($keys, max(0, $keep)) as $gone) {
            $client->delete($gone);
            app('db')->delete('backup_copies', '`remote_id` = ? AND `remote_path` = ?', [$remoteId, $gone]);
            $n++;
        }
        return $n;
    }

    /**
     * Fetch a remote copy back into /var/backups for restore (ownership
     * chain verified: copy → backup → account). Skips download when the
     * artifact already exists locally. Returns the local filename.
     */
    public function fetchCopy(int $accountId, int $copyId): string
    {
        $copy = app('db')->selectOne(
            'SELECT c.*, b.`account_id`, b.`type` FROM `backup_copies` c
             JOIN `backups` b ON b.`id` = c.`backup_id`
             WHERE c.`id` = ? AND b.`account_id` = ?',
            [$copyId, $accountId]
        );
        if ($copy === null) {
            throw new \RuntimeException('Remote copy not found.');
        }
        $remote = app('db')->selectOne(
            'SELECT * FROM `backup_remotes` WHERE `id` = ? AND `account_id` = ?',
            [(int) $copy['remote_id'], $accountId]
        );
        if ($remote === null) {
            throw new \RuntimeException('Remote storage not found.');
        }
        $file = basename((string) $copy['remote_path']);
        if ($file === '' || str_contains($file, '/') || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
            throw new \RuntimeException('Unsafe remote filename.');
        }
        $local = self::BACKUP_DIR . '/' . $file;
        $real = realpath($local);
        if ($real !== false && str_starts_with($real, self::BACKUP_DIR . '/') && is_file($real)) {
            return $file; // already here (e.g. retention kept it)
        }
        $tmp = $local . '.dl-' . getmypid();
        try {
            $this->remoteClient($remote)->get((string) $copy['remote_path'], $tmp);
            rename($tmp, $local);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
        return $file;
    }

    /**
     * Connectivity + credential proof for a remote row: PUT a probe object,
     * HEAD it, DELETE it. Returns a summary (server/version when visible).
     */
    public function testRemote(array $remote): string
    {
        $client = $this->remoteClient($remote);
        $probe = 'cwhpanel-probe-' . bin2hex(random_bytes(6)) . '.txt';
        $account = $this->account((int) ($remote['account_id'] ?? 0));
        $prefix = $this->remotePrefix($remote, (string) ($account['username'] ?? 'probe'));
        $tmp = tempnam(sys_get_temp_dir(), 's3probe');
        file_put_contents($tmp, 'cwhpanel remote-storage probe ' . date('c'));
        try {
            $client->put($tmp, $prefix . $probe, 'text/plain');
            if (!$client->exists($prefix . $probe)) {
                throw new \RuntimeException('probe object not visible after PUT');
            }
            $client->delete($prefix . $probe);
        } finally {
            @unlink($tmp);
        }
        return 'PUT+HEAD+DELETE round-trip ok';
    }
}
