<?php
declare(strict_types=1);
namespace Modules\Core\Services;

/**
 * Multi-PHP version management.
 *
 * The system PHP is 8.4 (/usr/sbin/php-fpm). Additional versions come from Remi
 * SCL, installed to /opt/remi/phpXX/root/usr/sbin/php-fpm. This service maps
 * version strings to binaries, pool-name suffixes and FPM socket names, and
 * defines the editable per-website PHP options.
 */
class PhpVersionsService
{
    public const SYSTEM_VERSION = '8.4';

    /** Versions that may be offered/installed (two-digit SCL id => label). */
    public const AVAILABLE = [
        '74' => '7.4',
        '80' => '8.0',
        '81' => '8.1',
        '82' => '8.2',
        '83' => '8.3',
        '84' => '8.4 (system)',
    ];

    /**
     * Installable PHP extensions (mirrors the agent's php-module allowlist).
     * Covers WordPress + major CMS needs (db/media/cache/search): base names
     * resolve to php[-*]-<name>, pecl ones to php[-*]-pecl-<name> per version.
     * curl/fileinfo/exif/sockets and friends ship bundled and need no entry.
     * mysqli is managed (WordPress hard-requires it) but NOT customer-
     * togglable off: the base-link pass skips manageable modules, so without
     * this entry WP installs die with "missing the MySQL extension".
     */
    public const MODULES = [
        'bcmath', 'enchant', 'gmp', 'imap', 'ldap', 'pgsql', 'process',
        'snmp', 'soap', 'sodium', 'tidy', 'mysqli',
        'apcu', 'imagick', 'memcached', 'yaml', 'zip',
    ];

    /** Modules the customer may toggle per site (subset of MODULES). */
    public const TOGGLABLE = [
        'bcmath', 'enchant', 'gmp', 'imap', 'ldap', 'pgsql', 'process',
        'snmp', 'soap', 'sodium', 'tidy',
        'apcu', 'imagick', 'memcached', 'yaml', 'zip',
    ];

    /**
     * Per-website PHP options. key => [label, type, default, allowed values|null]
     */
    public const OPTIONS = [
        'memory_limit' => ['Memory limit', 'text', '256M', null],
        'upload_max_filesize' => ['Max upload size', 'text', '32M', null],
        'post_max_size' => ['Max POST size', 'text', '32M', null],
        'max_execution_time' => ['Max execution time (s)', 'text', '120', null],
        'allow_url_fopen' => ['allow_url_fopen', 'onoff', '1', null],
        // allow_url_include defaults On (PHP/Remi stock); apps that refuse
        // to serve with it On (Dolphin 7.4 troubleshoots instead of
        // rendering) pin it Off via manifest php_settings on auto-pin, or
        // customers flip it per site/dir on the PHP Settings page.
        'allow_url_include' => ['allow_url_include', 'onoff', '1', null],
        'display_errors' => ['display_errors', 'onoff', '0', null],
        'expose_php' => ['expose_php', 'onoff', '1', null],
    ];

    /** Normalize a version string ("8.3", "83", "8.4") to a two-digit id. */
    public static function id(string $version): string
    {
        $digits = preg_replace('/\D/', '', $version);
        if (strlen($digits) >= 2) {
            return substr($digits, 0, 2);
        }
        return '84';
    }

    /** Whether a version string ("8.3" or "83") names a supported version. */
    public static function isAvailable(string $version): bool
    {
        $v = trim($version);
        if (isset(self::AVAILABLE[$v])) {
            return true;
        }
        if (preg_match('/^(\d)\.(\d)$/', $v, $m) === 1) {
            return isset(self::AVAILABLE[$m[1] . $m[2]]);
        }
        return false;
    }

    /** Full label for a two-digit id. */
    public static function label(string $id): string
    {
        return self::AVAILABLE[$id] ?? (substr($id, 0, 1) . '.' . substr($id, 1, 1));
    }

    /**
     * The package's default PHP version for an account (two-digit id).
     * New accounts and explicitly-unversioned websites inherit it. Falls back
     * to the system version when the account/package/column is unavailable.
     */
    public static function packageDefaultForAccount(?int $accountId): string
    {
        if ($accountId === null || $accountId <= 0) {
            return '84';
        }

        try {
            $row = app('db')->selectOne(
                'SELECT p.`php_version` FROM `accounts` a'
                . ' JOIN `packages` p ON p.`id` = a.`package_id`'
                . ' WHERE a.`id` = ?',
                [$accountId]
            );
            $v = (string) ($row['php_version'] ?? '');

            return self::isAvailable($v) ? self::id($v) : '84';
        } catch (\Throwable) {
            return '84';
        }
    }

    /**
     * Package-level PHP ceilings for an account (0 = engine default).
     * Cached per request.
     *
     * @return array{max_children:int,memory_limit_mb:int,max_execution_time:int}
     */
    public function packageLimits(array $account): array
    {
        static $cache = [];
        $accountId = (int) ($account['id'] ?? 0);
        $packageIdEarly = (int) ($account['package_id'] ?? 0);
        $cacheKey = $accountId > 0 ? 'a' . $accountId : 'p' . $packageIdEarly;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $limits = ['max_children' => 0, 'memory_limit_mb' => 0, 'max_execution_time' => 0];
        $packageId = (int) ($account['package_id'] ?? 0);
        try {
            $row = $accountId > 0
                ? app('db')->selectOne(
                    'SELECT p.`php_max_children`, p.`php_memory_limit_mb`, p.`php_max_execution_time`
                       FROM `accounts` a JOIN `packages` p ON p.`id` = a.`package_id`
                      WHERE a.`id` = ?',
                    [$accountId]
                )
                : ($packageId > 0
                    ? app('db')->selectOne(
                        'SELECT `php_max_children`, `php_memory_limit_mb`, `php_max_execution_time` FROM `packages` WHERE `id` = ?',
                        [$packageId]
                    )
                    : null);
            if (is_array($row)) {
                $neg = static fn (int $v): int => $v < 0 ? -1 : max(0, $v);
                $limits['max_children'] = $neg((int) ($row['php_max_children'] ?? 0));
                $limits['memory_limit_mb'] = $neg((int) ($row['php_memory_limit_mb'] ?? 0));
                $limits['max_execution_time'] = $neg((int) ($row['php_max_execution_time'] ?? 0));
            }
        } catch (\Throwable $e) {
            // Broken/legacy package row: engine defaults (fail open).
        }

        return $cache[$cacheKey] = $limits;
    }

    /** Effective pm.max_children (package value, else the 8-worker default). */
    public function maxChildrenForAccount(array $account): int
    {
        $n = $this->packageLimits($account)['max_children'];

        // -1 = unlimited: PHP-FPM still needs a finite pool, so use the
        // agent's hard ceiling (64). 0 = engine default 8.
        if ($n < 0) {
            return 64;
        }

        return $n > 0 ? min(64, $n) : 8;
    }

    /** "256M" / "1G" / "-1" → bytes (-1 = unlimited). Null when unparseable. */
    public static function memoryToBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        if (preg_match('/^(\d+)\s*([KMG]?)B?$/i', $value, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];
        $factor = match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };

        return $n * $factor;
    }

    /**
     * Merge site PHP settings with package ceilings. Precedence: explicit
     * site value -> package value -> engine default. Package values are
     * CEILINGS: a site value above the cap is clamped down (audited), so the
     * limit is enforced, not merely offered. 0 = no cap.
     *
     * @param array<string,string> $siteSettings
     * @return array<string,string>
     */
    public function effectiveSettings(array $account, array $siteSettings): array
    {
        $limits = $this->packageLimits($account);
        $out = $siteSettings;

        // -1 = unlimited: PHP expresses it as memory_limit=-1 and
        // max_execution_time=0; the value must override any site default.
        if ($limits['memory_limit_mb'] < 0) {
            if (($out['memory_limit'] ?? '') !== '-1') {
                $out['memory_limit'] = '-1';
                $this->auditClamp('memory_limit', (string) ($out['memory_limit'] ?? ''), '-1');
            }
        }

        if ($limits['memory_limit_mb'] > 0) {
            $cap = $limits['memory_limit_mb'];
            $have = trim((string) ($out['memory_limit'] ?? ''));
            $bytes = $have === '' ? null : self::memoryToBytes($have);
            if ($bytes === null || $bytes < 0 || $bytes > $cap * 1048576) {
                $out['memory_limit'] = $cap . 'M';
                $this->auditClamp('memory_limit', $have, $cap . 'M');
            }
        }

        if ($limits['max_execution_time'] < 0) {
            if (($out['max_execution_time'] ?? '') !== '0') {
                $out['max_execution_time'] = '0';
                $this->auditClamp('max_execution_time', (string) ($out['max_execution_time'] ?? ''), '0');
            }
        }

        if ($limits['max_execution_time'] > 0) {
            $cap = $limits['max_execution_time'];
            $have = trim((string) ($out['max_execution_time'] ?? ''));
            if ($have === '' || !ctype_digit($have) || (int) $have === 0 || (int) $have > $cap) {
                $out['max_execution_time'] = (string) $cap;
                $this->auditClamp('max_execution_time', $have, (string) $cap);
            }
        }

        return $out;
    }

    /** Audit a clamp once per key per request (no log spam on fleet sweeps). */
    private function auditClamp(string $key, string $from, string $to): void
    {
        static $seen = [];
        $sig = $key . '|' . $from . '|' . $to;
        if (isset($seen[$sig])) {
            return;
        }
        $seen[$sig] = true;
        audit('php.settings.clamped', 'php_version', null, [
            'key' => $key, 'from' => $from, 'to' => $to,
        ]);
        app('logger')->info("PHP setting {$key} clamped from '{$from}' to '{$to}' by package limit.");
    }

    /**
     * CLI binary path for a version (cron jobs, occ/console runs). This is
     * deliberately separate from the FPM daemon binary (the FPM binary
     * cannot run scripts — bitten live: Mautic cron rows pointed at php-fpm).
     */
    public static function cliBinary(string $version): string
    {
        return self::id($version) === '84'
            ? '/usr/bin/php'
            : '/opt/remi/php' . self::id($version) . '/root/usr/bin/php';
    }

    /** Pool-name suffix ('' for the default 8.4, "_phpXX" otherwise). */
    public static function poolSuffix(string $version): string
    {
        $id = self::id($version);
        return $id === '84' ? '' : "_php{$id}";
    }

    /** FPM socket path for an account + version. */
    public static function socket(string $username, string $version): string
    {
        return '/run/php-fpm/cwh_' . $username . self::poolSuffix($version) . '.sock';
    }

    /**
     * Detect installed PHP versions (by probing for FPM binaries). Always
     * includes the system 8.4. Returns [id => label].
     */
    public function installed(): array
    {
        $out = ['84' => self::AVAILABLE['84']];
        try {
            $r = app('provisioning')->execute('php.versions.list', []);
            $raw = (string) ($r['output'] ?? '');
            foreach (preg_split('/\s+/', trim($raw)) as $line) {
                if (preg_match('#/opt/remi/php(\d{2})/root/usr/sbin/php-fpm#', $line, $m)) {
                    $out[$m[1]] = self::AVAILABLE[$m[1]] ?? ($m[1][0] . '.' . $m[1][1]);
                }
            }
        } catch (\Throwable $e) {
            // return system-only
        }
        ksort($out);
        return $out;
    }

    /** Install an additional PHP version via the agent (dnf/Remi). */
    public function install(string $version): void
    {
        $id = self::id($version);
        if ($id === '84') {
            throw new \RuntimeException('PHP 8.4 is already the system version.');
        }
        app('provisioning')->execute('php.versions.install', ['version' => $id]);
    }

    /**
     * Remove a PHP version via the agent. Refuses the system version,
     * versions that are not installed, and versions still assigned to
     * websites (move those sites first — never strand a vhost).
     */
    public function uninstall(string $version): void
    {
        $id = self::id($version);
        if ($id === '84') {
            throw new \RuntimeException('PHP 8.4 is the system version and cannot be removed.');
        }
        if (!isset($this->installed()[$id])) {
            throw new \RuntimeException('PHP ' . self::label($id) . ' is not installed.');
        }
        $users = app('db')->select(
            'SELECT `label` FROM `websites` WHERE `php_version` = ? OR `php_version` = ? ORDER BY `label`',
            [self::label($id), $id]
        );
        try {
            $dirUsers = app('db')->select(
                'SELECT DISTINCT w.`label` FROM `website_php_dirs` d
                 JOIN `websites` w ON w.`id` = d.`website_id`
                 WHERE d.`php_version` = ? OR d.`php_version` = ? ORDER BY w.`label`',
                [self::label($id), $id]
            );
        } catch (\Throwable $e) {
            $dirUsers = []; // pre-migration
        }
        foreach ($dirUsers as $du) {
            $users[] = ['label' => $du['label'] . ' (per-directory override)'];
        }
        if ($users !== []) {
            throw new \RuntimeException(
                'PHP ' . self::label($id) . ' is still used by: ' . implode(', ', array_column($users, 'label')) . '. Move those sites first.'
            );
        }
        app('provisioning')->execute('php.versions.uninstall', ['version' => $id]);
        // The dnf glob once left php-fpm behind while reporting success —
        // re-probe so a half-removed version fails loudly instead of lingering.
        if (isset($this->installed()[$id])) {
            throw new \RuntimeException('Removal incomplete: PHP ' . self::label($id) . ' is still present.');
        }
        // Tear down every pooled pool still carrying this version. The Remi
        // packages are gone, so a leftover pool would fail every request.
        try {
            $accounts = app('db')->select('SELECT `username` FROM `accounts`');
        } catch (\Throwable $e) {
            $accounts = [];
        }
        foreach ($accounts as $account) {
            $username = (string) $account['username'];
            try {
                app('provisioning')->execute('php.pool.delete', [
                    'account' => $username,
                    'version' => $id,
                ]);
            } catch (\Throwable $e) {
                app('logger')->warning('PHP ' . self::label($id) . ' pool teardown failed for ' . $username . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Loaded extensions for a version (from `php -m`). Returns lowercase
     * names; agents failures degrade to an empty list (never fatal to pages).
     *
     * @return array<int,string>
     */
    public function loadedModules(string $version): array
    {
        try {
            $r = app('provisioning')->execute('php.modules.list', ['version' => self::id($version)]);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s+/', trim((string) ($r['output'] ?? ''))) as $name) {
            $name = strtolower(trim($name));
            // Skip `php -m` section headers ("[PHP Modules]" splits into
            // bracket tokens) and blanks.
            if ($name === '' || str_starts_with($name, '[')) {
                continue;
            }
            $out[] = $name;
        }
        return array_values(array_unique($out));
    }

    /** Install one allowlisted extension for a version (agent dnf + daemon reload). */
    public function installModule(string $version, string $module): void
    {
        $module = strtolower(trim($module));
        if (!in_array($module, self::MODULES, true)) {
            throw new \RuntimeException('Unknown PHP module.');
        }
        app('provisioning')->execute('php.modules.install', [
            'version' => self::id($version),
            'module' => $module,
        ]);
    }

    /**
     * Ensure the account's dedicated master for a version: writes conf +
     * pool + unit + ini links, starts it, and adopts the pool away from the
     * shared version daemon. $settings is [OPTION key => value] (validated
     * again agent-side); $modules lists manageable extensions to link —
     * pass the current enabled set so rewrites never clobber toggles.
     */
    public function ensurePool(array $account, string $version, ?array $settings = null): void
    {
        $id = self::id($version);
        $username = (string) ($account['username'] ?? '');
        if ($username === '') {
            throw new \RuntimeException('Account has no username.');
        }
        $args = [
            'account' => $username,
            'version' => $id,
            'base_dir' => (string) ($account['home_dir'] ?? '/home/' . $username),
        ];
        $opts = [];
        foreach (($settings ?? []) as $key => $value) {
            if (isset(self::OPTIONS[$key])) {
                $opts[$key] = (string) $value;
            }
        }

        // Package ceilings: effective (clamped) memory/exec-time values plus
        // the pm.max_children cap, for both master and pooled paths.
        $opts = $this->effectiveSettings($account, $opts);
        foreach ($opts as $key => $value) {
            $args['set_' . $key] = (string) $value;
        }
        $maxChildren = (string) $this->maxChildrenForAccount($account);

        $pool = [
            'account' => $username,
            'version' => $id,
            'base_dir' => (string) ($args['base_dir'] ?? '/home/' . $username),
            'max_children' => $maxChildren,
        ];
        foreach ($args as $k => $v) {
            if (str_starts_with((string) $k, 'set_')) {
                $pool[$k] = (string) $v;
            }
        }
        app('provisioning')->execute('php.pool.write', $pool);
    }


    /**
     * Delete the account pool for a version when none of the account's
     * websites use it anymore (post version-switch cleanup). Silent no-op
     * when still in use.
     */
    public function pruneVersion(array $account, string $version): void
    {
        $id = self::id($version);
        $user = (string) ($account['username'] ?? '');
        try {
            $row = app('db')->selectOne(
                'SELECT COUNT(*) AS n FROM `websites` w JOIN `accounts` a ON a.`id` = w.`account_id`
                 WHERE a.`username` = ? AND (w.`php_version` = ? OR w.`php_version` = ?)',
                [$user, self::label($id), $id]
            );
            $sites = (int) ($row['n'] ?? 1);
            $dirs = 0;
            try {
                $drow = app('db')->selectOne(
                    'SELECT COUNT(*) AS n FROM `website_php_dirs` d
                     JOIN `websites` w ON w.`id` = d.`website_id`
                     JOIN `accounts` a ON a.`id` = w.`account_id`
                     WHERE a.`username` = ? AND (d.`php_version` = ? OR d.`php_version` = ?)',
                    [$user, self::label($id), $id]
                );
                $dirs = (int) ($drow['n'] ?? 0);
            } catch (\Throwable $ignored) {
            }
        } catch (\Throwable $e) {
            return;
        }
        if ($sites === 0 && $dirs === 0) {
            try {
                app('provisioning')->execute('php.pool.delete', ['account' => $user, 'version' => $id]);
            } catch (\Throwable $e) {
                app('logger')->warning('Pool prune failed: ' . $e->getMessage());
            }
        }
    }

    /** Delete every pooled pool for every known version (account termination). */
    public function deletePools(string $username): void
    {
        foreach (array_keys(self::AVAILABLE) as $id) {
            try {
                app('provisioning')->execute('php.pool.delete', [
                    'account' => $username,
                    'version' => (string) $id,
                ]);
            } catch (\Throwable $e) {
                app('logger')->warning('Pool teardown failed for ' . $username . ' php' . $id . ': ' . $e->getMessage());
            }
        }
    }

    /** Main php.ini path for a version (display only). */
    public static function iniPath(string $version): string
    {
        return self::id($version) === '84'
            ? '/etc/php.ini'
            : '/etc/opt/remi/php' . self::id($version) . '/php.ini';
    }

    /** Read the full main php.ini for a version (admin edit). */
    public function readIni(string $version): string
    {
        if (!isset($this->installed()[self::id($version)])) {
            throw new \RuntimeException('PHP ' . self::label(self::id($version)) . ' is not installed.');
        }
        $r = app('provisioning')->execute('php.ini.read', ['version' => self::id($version)]);
        return (string) ($r['output'] ?? '');
    }

    /**
     * Replace the full main php.ini for a version. The agent validates with
     * `php-fpm -t` and rolls back on failure, so a malformed save can never
     * take a version down.
     */
    public function writeIni(string $version, string $contents): void
    {
        // Normalize line endings only: NO trim(). A save (and the rollback on
        // a malformed save) must be byte-exact with the file it replaces —
        // trimming leading/trailing whitespace silently mutated php.ini on
        // every no-op save and broke byte-exact restore/rollback.
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        if (strlen($contents) > 1_000_000 || str_contains($contents, "\0")) {
            throw new \RuntimeException('php.ini content is too large.');
        }
        app('provisioning')->execute('php.ini.write', [
            'version' => self::id($version),
            'contents' => $contents,
        ]);
    }

    /**
     * Parse the effective `disable_functions` list out of ini text: the last
     * active (non-comment) directive wins, values may be quoted, and each
     * entry is trimmed of surrounding whitespace. Normalizes to
     * lowercase-sorted unique names for easy comparison.
     *
     * @return array<int,string>
     */
    public static function disabledFunctionsFromIni(string $ini): array
    {
        $value = null;
        foreach (preg_split('/\r?\n/', $ini) as $line) {
            if (preg_match('/^\s*disable_functions\s*=\s*(.*)$/i', $line, $m) === 1) {
                $raw = trim($m[1]);
                if ($raw === '' || $raw === ';' || str_starts_with($raw, ';')) {
                    continue;
                }
                // Strip an optional wrapping quote pair.
                if (strlen($raw) >= 2 && $raw[0] === '"' && $raw[strlen($raw) - 1] === '"') {
                    $raw = substr($raw, 1, -1);
                }
                $value = $raw;
            }
        }
        if ($value === null || trim($value) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $value) as $fn) {
            $fn = trim($fn);
            if ($fn !== '') {
                $out[] = strtolower($fn);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Set the `disable_functions` directive in the version's php.ini without
     * touching the rest of the file: every existing active directive is
     * removed and a single managed line is appended (the last definition
     * wins for this PHP_INI_SYSTEM key). An empty list restores the stock
     * behavior by clearing the directive entirely.
     *
     * @param array<int,string> $functions
     */
    public function setDisabledFunctions(string $version, array $functions): void
    {
        $functions = array_values(array_filter(array_map(
            static fn ($f) => strtolower(trim((string) $f)),
            $functions
        ), static fn ($f) => $f !== ''));
        $functions = array_values(array_unique($functions));
        foreach ($functions as $fn) {
            if (!preg_match('/^[a-z0-9_]{1,64}$/', $fn)) {
                throw new \RuntimeException('Invalid PHP function name: ' . $fn);
            }
        }
        if (count($functions) > 200) {
            throw new \RuntimeException('Too many disabled functions (200 max).');
        }

        $ini = $this->readIni($version);
        $lines = preg_split('/\r?\n/', $ini);
        $out = [];
        foreach ($lines as $line) {
            // Our own managed marker comments (repeated edits must not
            // accumulate duplicate annotations).
            if (str_contains($line, '; cwhpanel managed: disable_functions')) {
                continue;
            }
            // Active directives are replaced from the end. A STOCK empty
            // directive (`disable_functions =` with no value, as shipped by
            // the template) is preserved when clearing so a set→clear cycle
            // restores the file byte-exact.
            if (preg_match('/^\s*disable_functions\s*=\s*(.*)$/i', $line, $m) === 1) {
                $val = trim($m[1] ?? '');
                if ($functions === [] && $val === '') {
                    $out[] = $line;
                }
                continue;
            }
            $out[] = $line;
        }
        if ($functions !== []) {
            $out[] = '';
            $out[] = '; cwhpanel managed: disable_functions (Server > PHP Versions > PHP config)';
            $out[] = 'disable_functions = "' . implode(',', $functions) . '"';
        }
        $this->writeIni($version, implode("\n", $out));
        audit('php.ini.disable_functions', 'php_version', null, [
            'version' => self::id($version),
            'count' => count($functions),
        ]);
    }
}