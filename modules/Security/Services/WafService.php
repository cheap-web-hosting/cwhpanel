<?php
declare(strict_types=1);
namespace Modules\Security\Services;

/**
 * WAF (ModSecurity + OWASP CRS) management.
 *
 * Enforcement model: CRS is loaded once at nginx http level
 * (/etc/modsecurity/cwhpanel-crs.conf); each WAF-enabled website gets a
 * per-domain conf with its SecRuleEngine mode plus any SecRuleRemoveById
 * exclusions from `website_waf_exclusions`. Isolated workers strip the
 * WAF block — inspection happens at the front-door proxy only.
 *
 * Attack visibility: the shared serial audit log
 * (/var/log/nginx/modsec_audit) is tailed root-privileged via the
 * `log.read` agent op and attributed to websites by hostname.
 */
class WafService
{
    /** Reentrancy guard: the vhost generator self-heals by calling back. */
    private static bool $refreshingVhost = false;

    public const AUDIT_LOG = '/var/log/nginx/modsec_audit';

    /**
     * Whether new websites should have the WAF enabled by default.
     * Operator-overridable via the `security.waf_default` setting
     * ('0' disables; any other/missing value = enabled).
     */
    /**
     * Is ModSecurity actually available on this host?
     *
     * The nginx-mod-modsecurity RPM loads the module from
     * /etc/nginx/modules and does NOT ship /etc/modsecurity/modsecurity.conf,
     * so checking that one path reported "Not installed" on a working WAF
     * (bitten in the admin Security overview).
     */
    public function installed(): bool
    {
        foreach ([
            '/etc/nginx/modules/ngx_http_modsecurity_module.so',
            '/usr/lib64/nginx/modules/ngx_http_modsecurity_module.so',
            '/etc/nginx/modsec/modsecurity.conf',
            '/etc/modsecurity/modsecurity.conf',
            '/etc/httpd/modsecurity.d/modsecurity.conf',
            '/etc/modsecurity/cwhpanel-crs.conf',
        ] as $path) {
            if (@is_file($path)) {
                return true;
            }
        }
        return false;
    }

    public function defaultEnabled(): bool
    {
        try {
            $row = app('db')->selectOne(
                "SELECT `value` FROM `settings` WHERE `key` = ?",
                ['security.waf_default']
            );
        } catch (\Throwable $e) {
            return true;
        }
        return $row === null || (string) $row['value'] !== '0';
    }

    /** @return int[] sorted disabled rule IDs for a website */
    public function exclusions(int $websiteId): array
    {
        try {
            $rows = app('db')->select(
                'SELECT `rule_id` FROM `website_waf_exclusions` WHERE `website_id` = ? ORDER BY `rule_id`',
                [$websiteId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_map(fn ($r) => (int) $r['rule_id'], $rows));
    }

    /** Normalize a user-supplied rule ID, or null when invalid. */
    public static function validateRuleId(mixed $raw): ?int
    {
        $s = trim((string) $raw);
        if (!preg_match('/^\d{1,8}$/', $s)) {
            return null;
        }
        $id = (int) $s;
        return $id >= 1 ? $id : null;
    }

    /** Disable one CRS rule for a website and regenerate its conf. */
    public function addExclusion(int $websiteId, int $ruleId, string $reason = '', bool $coalesce = false): void
    {
        try {
            app('db')->statement(
                'INSERT INTO `website_waf_exclusions` (`website_id`, `rule_id`, `reason`, `created_at`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `reason`=VALUES(`reason`)',
                [$websiteId, $ruleId, mb_substr(trim($reason), 0, 191), date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not store the exclusion: ' . $e->getMessage());
        }
        $this->regenerate($websiteId, $coalesce);
    }

    /** Re-enable one CRS rule for a website and regenerate its conf. */
    public function removeExclusion(int $websiteId, int $ruleId, bool $coalesce = false): void
    {
        app('db')->delete('website_waf_exclusions', '`website_id` = ? AND `rule_id` = ?', [$websiteId, $ruleId]);
        $this->regenerate($websiteId, $coalesce);
    }

    /** Full per-domain ModSecurity conf content for an engine mode. */
    public function renderConf(string $engine, array $ruleIds): string
    {
        $out = "# cwhpanel generated ModSecurity rules\nSecRuleEngine {$engine}\n";
        $ids = array_values(array_unique(array_map('intval', $ruleIds)));
        sort($ids);
        if ($ids !== []) {
            $out .= '# Disabled rules (per-site exclusions)' . "\n";
            $out .= 'SecRuleRemoveById ' . implode(' ', $ids) . "\n";
        }
        return $out;
    }

    /**
     * Rewrite the per-domain conf from the current settings + exclusions
     * (or delete it when the WAF is off). Never throws — callers already
     * log provisioning failures as warnings.
     */
    /**
     * @param bool $coalesce write the vhost without reloading nginx and queue a
     *                       single short-delayed reload (customer WAF path, audit #23)
     */
    public function regenerate(int $websiteId, bool $coalesce = false): void
    {
        $site = app('db')->selectOne(
            "SELECT w.*, d.`name` AS domain FROM `websites` w
             JOIN `domains` d ON d.id=w.domain_id WHERE w.id=?",
            [$websiteId]
        );
        if ($site === null || empty($site['domain'])) {
            return;
        }
        $settings = app('db')->selectOne(
            'SELECT * FROM `security_settings` WHERE `website_id` = ?',
            [$websiteId]
        );
        $enabled = $settings !== null && !empty($settings['waf_enabled']);
        // ORDER MATTERS, the same way it does in the controller: the agent's
        // `waf.rules.delete` runs `nginx -t` after removing the file, and a
        // vhost that still references it makes that test fail host-wide. So:
        //   enable  -> write the file FIRST, then refresh the vhost;
        //   disable -> refresh the vhost FIRST (drops the directive), then
        //              delete the file.
        try {
            if ($enabled) {
                $engine = (($settings['waf_mode'] ?? '') === 'on') ? 'On' : 'DetectionOnly';
                $rules = $this->renderConf($engine, $this->exclusions($websiteId));
                app('provisioning')->execute('waf.rules.write', ['domain' => $site['domain'], 'rules' => $rules]);
            }
        } catch (\Throwable $e) {
            // A silent swallow here meant a customer WAF change (reload is a
            // customer op) wrote the rules file but never reloaded nginx, so
            // the exclusion had no effect while the UI said "disabled".
            app('logger')->warning('WAF regenerate failed: ' . $e->getMessage());
            throw new \RuntimeException('Could not apply the WAF change: ' . $e->getMessage());
        }

        // (Disable continues below, after the vhost refresh.)
        if (!$enabled) {
            // vhost refresh happens next; the delete must come after it.
        }

        // Keep the referencing vhost in step. A rules file deleted while a
        // vhost still points at it makes `nginx -t` fail for the WHOLE host;
        // the reverse order (file written after the vhost) silently serves
        // without protection. Regenerate both sides here, guarded against the
        // generator's own self-heal calling back into regenerate().
        if (!self::$refreshingVhost) {
            self::$refreshingVhost = true;
            try {
                $account = app('db')->selectOne(
                    'SELECT * FROM `accounts` WHERE `id` = ?',
                    [(int) ($site['account_id'] ?? 0)]
                );
                $domainRow = app('db')->selectOne(
                    'SELECT * FROM `domains` WHERE `id` = ?',
                    [(int) ($site['domain_id'] ?? 0)]
                );
                if ($account !== null && $domainRow !== null) {
                    $cfg = app(\Modules\Websites\Services\NginxConfigGenerator::class)
                        ->generate($site, $domainRow, $account);
                    app('db')->update(
                        'websites',
                        ['nginx_config' => $cfg, 'updated_at' => date('Y-m-d H:i:s')],
                        '`id` = ?',
                        [(int) $websiteId]
                    );
                    app(\Modules\Core\Services\NginxIsolationService::class)
                        ->pushVhost($account, (string) $site['domain'], $cfg, $coalesce);
                    if ($coalesce && !\App\Foundation\Provisioning\NginxReloadCoalescer::schedule()) {
                        // Coalescing unavailable (DB/queue failure): apply now.
                        app('provisioning')->execute('nginx.reload', []);
                    }
                }
            } catch (\Throwable $e) {
                app('logger')->warning('WAF vhost refresh failed for ' . $site['domain'] . ': ' . $e->getMessage());
            } finally {
                self::$refreshingVhost = false;
            }
        }

        // Safe now: no live vhost carries the directive any more.
        if (!$enabled) {
            try {
                app('provisioning')->execute('waf.rules.delete', ['domain' => $site['domain']]);
            } catch (\Throwable $e) {
                app('logger')->warning('WAF rules delete failed for ' . $site['domain'] . ': ' . $e->getMessage());
                throw new \RuntimeException('Could not apply the WAF change: ' . $e->getMessage());
            }
        }

        // The conf write only tests (`nginx -t`); the running workers still
        // enforce the previous file until a reload.
        try {
            app('provisioning')->execute('nginx.reload', []);
        } catch (\Throwable $e) {
            app('logger')->warning('WAF reload failed: ' . $e->getMessage());
            throw new \RuntimeException('Could not apply the WAF change: ' . $e->getMessage());
        }
    }

    /**
     * Recent audit-log blocks/detections, newest first, attributed to
     * websites. Pass an account ID to scope to one customer (null = all).
     *
     * @return array<int,array{time:string,ip:string,host:string,uri:string,rule:int,msg:string,blocked:bool,website_id:?int,domain:?string,username:?string}>
     */
    public function recentBlocks(?int $accountId = null, int $limit = 50): array
    {
        $text = $this->tailAuditLog();
        if ($text === null || $text === '') {
            return [];
        }
        $rows = self::parseAudit($text);
        if ($rows === []) {
            return [];
        }
        // Attribute hostnames to websites (one lookup per distinct host).
        $byHost = [];
        foreach (array_unique(array_column($rows, 'host')) as $host) {
            if ($host === '') {
                continue;
            }
            $site = app('db')->selectOne(
                "SELECT w.`id` AS website_id, d.`name` AS domain, a.`username`, w.`account_id`
                 FROM `websites` w JOIN `domains` d ON d.id=w.domain_id
                 LEFT JOIN `accounts` a ON a.id=w.account_id
                 WHERE d.`name` = ? OR d.`name` = ? LIMIT 1",
                [$host, preg_replace('/^www\./i', '', $host)]
            );
            $byHost[$host] = $site ?: null;
        }
        $out = [];
        foreach ($rows as $row) {
            $site = $byHost[$row['host']] ?? null;
            $row['website_id'] = $site ? (int) $site['website_id'] : null;
            $row['domain'] = $site ? (string) ($site['domain'] ?? $row['host']) : $row['host'];
            $row['username'] = $site ? (string) ($site['username'] ?? '') : '';
            if ($accountId !== null && (int) ($site['account_id'] ?? -1) !== $accountId) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Parse serial-format ModSecurity audit log text. One output row per
     * matched rule (entry × rule), newest first. Pure function — unit
     * testable without any log file.
     *
     * @return array<int,array{time:string,ip:string,host:string,uri:string,rule:int,msg:string,blocked:bool}>
     */
    public static function parseAudit(string $text): array
    {
        $entries = preg_split('/^---+[0-9A-Za-z]+---+Z--$/m', $text) ?: [];
        $out = [];
        foreach ($entries as $entry) {
            if (!str_contains($entry, 'ModSecurity:')) {
                continue;
            }
            $time = '';
            $ip = '';
            if (preg_match('/^---+[0-9A-Za-z]+---+A--\s*\[(.*?)\]\s+\S+\s+(\d+\.\d+\.\d+\.\d+|[0-9a-fA-F:]+)/m', $entry, $m)) {
                $time = trim($m[1]);
                $ip = trim($m[2]);
            }
            // One row per matched rule message in section H.
            if (!preg_match_all('/ModSecurity:\s+(.*?)\s*\[id\s+"(\d+)"\](?:\s*\[msg\s+"((?:[^"\\\\]|\\\\.)*)"\])?/s', $entry, $mm, PREG_SET_ORDER)) {
                continue;
            }
            $host = '';
            $uri = '';
            if (preg_match('/\[hostname\s+"([^"]+)"\]/', $entry, $hm)) {
                $host = trim($hm[1]);
            }
            if (preg_match('/\[uri\s+"([^"]+)"\]/', $entry, $um)) {
                $uri = trim($um[1]);
            }
            foreach ($mm as $hit) {
                $head = $hit[1];
                $msg = isset($hit[3]) ? stripcslashes($hit[3]) : '';
                $out[] = [
                    'time' => $time,
                    'ip' => $ip,
                    'host' => $host,
                    'uri' => $uri,
                    'rule' => (int) $hit[2],
                    'msg' => mb_substr($msg !== '' ? $msg : trim(preg_replace('/\s+/', ' ', $head)), 0, 300),
                    'blocked' => stripos($head, 'Access denied') !== false || stripos($head, 'Denied') !== false,
                ];
            }
        }
        return array_reverse($out);
    }

    /** Tail the shared audit log via the privileged agent (null on failure). */
    private ?string $lastError = null;

    /** Last audit-read failure (null = fine), surfaced on the WAF pages. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function tailAuditLog(): ?string
    {
        try {
            // Capped at the agent's 64 KiB output cap: audit entries are
            // verbose (~10 KiB each), so this still holds the newest
            // handful — plenty for a recent-attacks list.
            $result = app('provisioning')->execute('log.read', [
                'path' => '/var/log/nginx/modsec_audit',
                'max_bytes' => '65536',
            ]);
        } catch (\Throwable $e) {
            $this->lastError = 'Audit log unavailable: ' . $e->getMessage();
            app('logger')->warning('WAF audit read failed: ' . $e->getMessage());
            return null;
        }
        if (($result['status'] ?? '') !== 'success') {
            $this->lastError = 'Audit log unavailable: agent returned ' . ($result['status'] ?? 'no status');
            return null;
        }
        return (string) ($result['output'] ?? '');
    }
}
