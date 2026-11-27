<?php

declare(strict_types=1);

namespace Modules\Websites\Services;

/**
 * RateLimitService — per-website nginx rate limiting (limit_req).
 *
 * Enforcement lives where the real client IP is visible: the shared
 * front-door nginx (direct vhosts AND the isolated-account proxy blocks).
 * Per-account workers only ever see 127.0.0.1, so the worker copy of a vhost
 * has its limit_req directives stripped (see NginxIsolationService) — the
 * same split as the WAF (enforced at the front-door, stripped in workers).
 *
 * Effective values resolve as website override ?? package default, so raising
 * a package tier automatically re-applies to inheriting sites.
 */
class RateLimitService
{
    private \App\Foundation\Database\Connection $db;

    public function __construct(\App\Foundation\Database\Connection $db)
    {
        $this->db = $db;
    }

    public const DEFAULT_RPS = 20;
    public const DEFAULT_BURST = 40;
    public const MIN_RPS = 1;
    public const MAX_RPS = 1000;
    public const MIN_BURST = 0;
    public const MAX_BURST = 5000;

    /**
     * Stable nginx zone name for a website row. Zone names share one http
     * namespace in the front-door nginx, so they key off the website id
     * (never the customer-controlled label).
     */
    public static function zoneName(int $websiteId): string
    {
        return 'cwh_rl_' . $websiteId;
    }

    /** Stable zone name for the per-site concurrent-connection counter. */
    public static function connZoneName(int $websiteId): string
    {
        return 'cwh_conn_' . $websiteId;
    }

    /**
     * Package defaults for an account (fall back to class defaults when the
     * account has no package row or the columns predate the migration).
     *
     * @return array{rps:int, burst:int}
     */
    public function packageDefaults(?array $account): array
    {
        // Legacy default (no package row / pre-migration column): 20 rps.
        // An explicit package value of 0 means "no default throttle"
        // (unlimited), so it must not be clamped up to the 1 rps floor.
        $rps = self::DEFAULT_RPS;
        $burst = self::DEFAULT_BURST;

        $packageId = (int) ($account['package_id'] ?? 0);
        if ($packageId > 0) {
            try {
                $row = $this->db->selectOne(
                    'SELECT `nginx_req_per_sec`, `nginx_req_burst` FROM `packages` WHERE `id` = ?',
                    [$packageId]
                );
            } catch (\Throwable $e) {
                $row = null;
            }
            if (is_array($row)) {
                if (isset($row['nginx_req_per_sec'])) {
                    $v = (int) $row['nginx_req_per_sec'];
                    $rps = $v <= 0 ? 0 : $this->clampRps($v);
                }
                if (isset($row['nginx_req_burst'])) {
                    $burst = $this->clampBurst((int) $row['nginx_req_burst']);
                }
            }
        }

        return ['rps' => $rps, 'burst' => $burst];
    }

    /**
     * Effective concurrent-connection cap. Per-site override (websites.
     * max_connections, NULL = inherit) ?? package max_connections.
     * 0 = no cap, so no zone/directive is rendered.
     *
     * @return array{cap:int, zone:?string, inherited:bool}
     */
    public function effectiveConnections(array $website, ?array $account = null): array
    {
        $websiteId = (int) ($website['id'] ?? 0);
        $cap = 0;
        $inherited = true;

        if (isset($website['max_connections']) && $website['max_connections'] !== null && $website['max_connections'] !== '') {
            $cap = max(0, (int) $website['max_connections']);
            $inherited = false;
        } else {
            if ($account === null && isset($website['account_id'])) {
                try {
                    $account = $this->db->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $website['account_id']]);
                } catch (\Throwable $e) {
                    $account = null;
                }
            }
            $packageId = (int) ($account['package_id'] ?? 0);
            if ($packageId > 0) {
                try {
                    $cap = max(0, (int) $this->db->scalar(
                        'SELECT `max_connections` FROM `packages` WHERE `id` = ?',
                        [$packageId]
                    ));
                } catch (\Throwable $e) {
                    $cap = 0;
                }
            }
        }

        return [
            'cap' => $cap,
            'zone' => ($cap > 0 && $websiteId > 0) ? self::connZoneName($websiteId) : null,
            'inherited' => $inherited,
        ];
    }

    /**
     * Effective rate-limit for a website row.
     *
     * @return array{enabled:bool, rps:int, burst:int, zone:?string, inherited:bool}
     */
    public function effective(array $website, ?array $account = null): array
    {
        $websiteId = (int) ($website['id'] ?? 0);
        $enabled = !empty($website['rate_limit_enabled']) && $websiteId > 0;

        if ($account === null && isset($website['account_id'])) {
            try {
                $account = $this->db->selectOne(
                    'SELECT * FROM `accounts` WHERE `id` = ?',
                    [(int) $website['account_id']]
                ) ?? null;
            } catch (\Throwable $e) {
                $account = null;
            }
        }

        $defaults = $this->packageDefaults($account);
        $inherited = true;
        $rps = $defaults['rps'];
        $burst = $defaults['burst'];

        if (isset($website['rate_limit_rps']) && $website['rate_limit_rps'] !== null && $website['rate_limit_rps'] !== '') {
            $v = (int) $website['rate_limit_rps'];
            $rps = $v <= 0 ? 0 : $this->clampRps($v);
            $inherited = false;
        }
        if (isset($website['rate_limit_burst']) && $website['rate_limit_burst'] !== null && $website['rate_limit_burst'] !== '') {
            $burst = $this->clampBurst((int) $website['rate_limit_burst']);
            $inherited = false;
        }

        // 0 rps = no throttle: nothing to enforce, so no zone is referenced.
        $enabled = $enabled && $rps > 0;

        return [
            'enabled' => $enabled,
            'rps' => $rps,
            'burst' => $burst,
            'zone' => $enabled ? self::zoneName($websiteId) : null,
            'inherited' => $inherited,
            'connections' => $this->effectiveConnections($website, $account)['cap'],
        ];
    }

    /**
     * Save per-website settings, regenerate + push the vhost through the
     * isolation-aware path, and re-sync the shared zone file. Empty
     * rps/burst strings mean "inherit the package default" (stored NULL).
     *
     * @throws \RuntimeException on invalid input
     */
    public function save(int $websiteId, bool $enabled, ?int $rps, ?int $burst, ?int $maxConnections = null): void
    {
        $site = $this->db->selectOne('SELECT * FROM `websites` WHERE `id` = ?', [$websiteId]);
        if ($site === null) {
            throw new \RuntimeException('Website not found.');
        }
        if ($maxConnections !== null && ($maxConnections < 0 || $maxConnections > 100000)) {
            throw new \RuntimeException('Connections must be 0-100000 (empty = package default).');
        }

        if ($rps !== null) {
            $this->assertRps($rps);
        }
        if ($burst !== null) {
            $this->assertBurst($burst);
        }

        $this->db->update('websites', [
            'rate_limit_enabled' => $enabled ? 1 : 0,
            'rate_limit_rps' => $rps,
            'rate_limit_burst' => $burst,
            'max_connections' => $maxConnections,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$websiteId]);

        // Ordering matters: `nginx -t` rejects a vhost referencing an unknown zone
        // (and a zone file dropping a zone that is still referenced). Use the
        // EFFECTIVE state: enabled + a positive rps means a zone is needed;
        // 0 rps = unlimited = no zone, so the vhost must be cleaned first.
        $fresh = $this->db->selectOne('SELECT * FROM `websites` WHERE `id` = ?', [$websiteId]) ?? [];
        $throttles = $this->effective($fresh)['enabled'];
        if ($throttles) {
            $this->syncZones();
        }
        try {
            app(PrivacyService::class)->regenerate($websiteId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Settings saved but vhost push failed: ' . $e->getMessage());
        }
        if (!$throttles) {
            $this->syncZones();
        }
    }

    /**
     * Rebuild the shared front-door zone file from every rate-limited active
     * website. Disabled-site zones are dropped so stale state never lingers.
     */
    public function syncZones(): void
    {
        $lines = ['# Managed by cwhpanel RateLimitService; do not edit.'];

        try {
            $rows = $this->db->select(
                'SELECT `id`, `rate_limit_enabled`, `rate_limit_rps`, `max_connections`, `account_id` FROM `websites`
                  WHERE `status` = ? ORDER BY `id`',
                ['active']
            );
        } catch (\Throwable $e) {
            app('logger')->warning('Rate-limit zone sync skipped (DB): ' . $e->getMessage());
            return;
        }

        foreach ($rows as $row) {
            $websiteId = (int) ($row['id'] ?? 0);
            if ($websiteId <= 0) {
                continue;
            }
            $account = null;
            try {
                $account = $this->db->selectOne(
                    'SELECT * FROM `accounts` WHERE `id` = ?',
                    [(int) ($row['account_id'] ?? 0)]
                ) ?? null;
            } catch (\Throwable $e) {
                $account = null;
            }
            if (!empty($row['rate_limit_enabled'])) {
                $eff = $this->effective($row, $account);
                // 0 rps = unlimited: no zone, nothing to reference.
                if ($eff['enabled'] && $eff['rps'] > 0) {
                    $lines[] = sprintf(
                        'limit_req_zone $binary_remote_addr zone=%s:10m rate=%dr/s;',
                        self::zoneName($websiteId),
                        $eff['rps']
                    );
                }
            }
            $conn = $this->effectiveConnections($row, $account);
            if ($conn['cap'] > 0) {
                $lines[] = sprintf(
                    'limit_conn_zone $server_name zone=%s:10m;',
                    self::connZoneName($websiteId)
                );
            }
        }

        try {
            app('provisioning')->execute('nginx.ratelimit.write', [
                'contents' => implode("\n", $lines) . "\n",
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('Rate-limit zone sync failed: ' . $e->getMessage());
            throw new \RuntimeException('Zone sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Panel-visible stats: throttled (429) hits in the access logs plus
     * "limiting requests" lines in the error logs. Reuses the same
     * agent-backed log tails as the log viewer (graceful null on failure).
     *
     * @return array{throttled_429:int, limited_events:int, total_requests:int, files:array<int,string>}|null
     */
    public function statsForWebsite(array $website): ?array
    {
        try {
            $tails = app(\Modules\Monitoring\Services\TrafficService::class)->tailLogs($website, 2097152);
        } catch (\Throwable $e) {
            return null;
        }

        $throttled = 0;
        $total = 0;
        $access = (string) ($tails['access'] ?? '');
        if ($access !== '') {
            foreach (explode("\n", $access) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $total++;
                if (preg_match('/"\s+429\s+/', $line)) {
                    $throttled++;
                }
            }
        }

        $limited = 0;
        $error = (string) ($tails['error'] ?? '');
        if ($error !== '') {
            $limited = substr_count($error, 'limiting requests');
        }

        $files = [];
        if (!empty($tails['accessFile'])) {
            $files[] = (string) $tails['accessFile'];
        }
        if (!empty($tails['errorFile'])) {
            $files[] = (string) $tails['errorFile'];
        }

        return [
            'throttled_429' => $throttled,
            'limited_events' => $limited,
            'total_requests' => $total,
            'files' => $files,
        ];
    }

    public function clampRps(int $rps): int
    {
        return max(self::MIN_RPS, min(self::MAX_RPS, $rps));
    }

    public function clampBurst(int $burst): int
    {
        return max(self::MIN_BURST, min(self::MAX_BURST, $burst));
    }

    /** @throws \RuntimeException */
    private function assertRps(int $rps): void
    {
        // 0 = unlimited (no throttle) — allowed on purpose.
        if ($rps < 0 || $rps > self::MAX_RPS) {
            throw new \RuntimeException('Requests/sec must be 0 (unlimited) or 1-' . self::MAX_RPS . '.');
        }
        if ($rps > 0 && $rps < self::MIN_RPS) {
            throw new \RuntimeException('Requests/sec must be 1-' . self::MAX_RPS . ' (0 = unlimited).');
        }
    }

    /** @throws \RuntimeException */
    private function assertBurst(int $burst): void
    {
        if ($burst < self::MIN_BURST || $burst > self::MAX_BURST) {
            throw new \RuntimeException('Burst must be ' . self::MIN_BURST . '-' . self::MAX_BURST . '.');
        }
    }
}
