<?php

declare(strict_types=1);

namespace Modules\Dns\Services;

class DnsService
{
    /** Record types the panel accepts (mirrors the agent ALLOWED list). */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR', 'SOA'];

    /**
     * Create a PowerDNS zone. $nameserver is the customer-supplied primary NS
     * and may be null: the zone is then created with an SOA mname inside the
     * domain itself and NO NS records, so the panel never claims the domain is
     * delegated to its own nameservers.
     */
    public function createZone(string $zone, ?string $nameserver = null, ?string $nameserver2 = null): bool
    {
        // No nameservers = DNS is hosted elsewhere. A PowerDNS zone without an
        // NS rrset is not authoritative (queries answer REFUSED), so creating
        // one would be junk data; the panel simply does not host this zone.
        if ($nameserver === null || $nameserver === '') {
            return false;
        }
        $args = ['zone' => $zone];
        if ($nameserver !== null && $nameserver !== '') {
            $args['ns'] = $nameserver;
        }
        $result = app('provisioning')->execute('dns.zone.create', $args);
        if ($result['status'] !== 'success') {
            return false;
        }
        // Nameservers are written as ONE rrset (replace-rrset): two calls would
        // leave only the second record. Empty = publish no NS at all.
        $this->setNameservers($zone, $nameserver, $nameserver2);
        return true;
    }

    public function deleteZone(string $zone): bool
    {
        try {
            $result = app('provisioning')->execute('dns.zone.delete', [
                'zone' => $zone,
            ]);
            return $result['status'] === 'success';
        } catch (\Throwable $e) {
            // Idempotent delete: a zone row can outlive its PowerDNS zone (a
            // partial earlier delete, a fixture row). "not found" is success.
            if (stripos($e->getMessage(), 'not found') !== false) {
                return true;
            }
            throw $e;
        }
    }

    public function secureZone(string $zone): bool
    {
        $result = app('provisioning')->execute('dns.zone.secure', [
            'zone' => $zone,
        ]);
        return $result['status'] === 'success';
    }

    public function unsecureZone(string $zone): bool
    {
        $result = app('provisioning')->execute('dns.zone.unsecure', [
            'zone' => $zone,
        ]);
        return $result['status'] === 'success';
    }

    /** Raw `show-zone` output (keys + DS records), or empty string. */
    public function showZone(string $zone): string
    {
        try {
            $result = app('provisioning')->execute('dns.zone.show', [
                'zone' => $zone,
            ]);
        } catch (\Throwable $e) {
            return '';
        }
        return (string) ($result['output'] ?? '');
    }

    /**
     * DS records from `show-zone` output, one per line as published
     * (keytag algo digest-type digest). Empty when not secured.
     *
     * @return array<int,string>
     */
    public static function parseDs(string $showOutput): array
    {
        $out = [];
        foreach (explode("\n", $showOutput) as $line) {
            if (preg_match('/^\s*DS\s*=\s*\S+\s+IN\s+DS\s+(\d+\s+\d+\s+\d+\s+[A-Fa-f0-9]+)/', $line, $m)) {
                $out[] = $m[1];
            }
        }
        return array_values(array_unique($out));
    }

    public function listZone(string $zone): string
    {
        $result = app('provisioning')->execute('dns.zone.list', [
            'zone' => $zone,
        ]);
        return $result['output'] ?? '';
    }

    /**
     * Qualify a user-entered record name to an FQDN in the zone for
     * pdnsutil (which rejects bare short names): '@'/empty → apex,
     * short names get .zone appended, FQDNs pass through unchanged.
     */
    public static function qualifyName(string $zone, string $name): string
    {
        $zone = strtolower(trim($zone));
        $name = strtolower(trim($name));
        if ($name === '' || $name === '@') return $zone;
        $name = rtrim($name, '.');
        if ($name === $zone || str_ends_with($name, '.' . $zone)) return $name;
        return $name . '.' . $zone;
    }

    public function upsertRecord(string $zone, string $name, string $type, string $content, int $ttl = 3600): bool
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, self::TYPES, true)) {
            return false;
        }
        // A/AAAA contents are validated panel-side (the agent trusts the
        // panel on values): a typo'd address must fail here, not publish.
        if ($type === 'A' && filter_var(trim($content), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if ($type === 'AAAA' && filter_var(trim($content), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }
        $result = app('provisioning')->execute('dns.record.upsert', [
            'zone' => $zone,
            'name' => self::qualifyName($zone, $name),
            'type' => $type,
            'content' => $content,
            'ttl' => $ttl,
        ]);
        return $result['status'] === 'success';
    }

    /**
     * Apply a DNS template (one record per line: TYPE|NAME|CONTENT|TTL) to a zone.
     *
     * Supports substitutions: {IP}, {IPV6}, {DOMAIN}, {MAIL}. {IPV6} resolves
     * to the account's dedicated IPv6 when one is assigned (callers pass it
     * via $vars), else empty — lines left with an empty content are skipped,
     * so v6-off templates stay clean.
     *
     * @return array{applied:int, failed:array<int,string>}
     */
    public function applyTemplate(string $records, string $zone, array $vars = []): array
    {
        $ip = env('PANEL_PUBLIC_IP', '');
        $ipv6 = (string) ($vars['IPV6'] ?? '');
        // MAIL_HOSTNAME is a PREFIX ("mail.") exactly as DomainService and
        // IpAllocatorService treat it, i.e. mail.<zone>. Legacy installs that
        // stored a full hostname are honoured as-is.
        $mailPrefix = (string) env('MAIL_HOSTNAME', 'mail.');
        $mail = str_ends_with($mailPrefix, '.') ? $mailPrefix . $zone : $mailPrefix;

        $applied = 0;
        $failed = [];

        foreach (explode("\n", trim($records)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('|', $line);
            if (count($parts) < 4) {
                $failed[] = 'Skipped malformed line: ' . $line;
                continue;
            }

            [$type, $name, $content, $ttl] = [
                strtoupper(trim($parts[0])),
                trim($parts[1]),
                trim($parts[2]),
                (int) trim($parts[3]),
            ];

            $name = str_replace(['{DOMAIN}', '{MAIL}'], [$zone, $mail], $name);
            $hasIpv6Var = str_contains($content, '{IPV6}');
            $content = str_replace(
                ['{IPV6}', '{IP}', '{DOMAIN}', '{MAIL}'],
                [$ipv6, $ip, $zone, $mail],
                $content
            );

            if ($type === '' || $content === '') {
                // An {IPV6} line with no address assigned is "not
                // applicable", not an error — skip silently so shared-IP
                // accounts don't get failure noise from v6 template lines.
                if (!($hasIpv6Var && $content === '')) {
                    $failed[] = 'Skipped incomplete line: ' . $line;
                }
                continue;
            }

            if ($type === 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                $failed[] = 'Skipped invalid IPv6: ' . $line;
                continue;
            }

            if ($this->upsertRecord($zone, $name, $type, $content, $ttl > 0 ? $ttl : 3600)) {
                $applied++;
            } else {
                $failed[] = 'Failed to apply: ' . $type . ' ' . $name;
            }
        }

        return ['applied' => $applied, 'failed' => $failed];
    }

    /**
     * Replace the zone's published NS rrset with the customer-supplied
     * nameservers (null = publish none). The panel's own nameservers are never
     * substituted: a domain delegated elsewhere must not advertise ours.
     * Best-effort — callers log failures; the panel metadata is updated too.
     */
    public function setNameservers(string $zone, ?string $ns1, ?string $ns2): void
    {
        $names = array_values(array_unique(array_filter(
            [trim((string) $ns1), trim((string) $ns2)],
            fn ($v) => $v !== ''
        )));

        try {
            app('provisioning')->execute('dns.ns.set', [
                'zone' => $zone,
                'ns' => implode(',', $names),
            ]);
            // Keep the SOA primary aligned with the published NS set.
            app('provisioning')->execute('dns.soa.set', [
                'zone' => $zone,
                'mname' => $names[0] ?? ('ns1.' . $zone),
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('NS/SOA set failed for ' . $zone . ': ' . $e->getMessage());
        }

        try {
            app('db')->update('dns_zones', [
                'nameserver' => $ns1,
                'nameserver2' => $ns2,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`name` = ?', [$zone]);
        } catch (\Throwable $e) {
            // dns_zones may predate the nameserver2 column on an old install.
        }
    }

    /**
     * Remove the values of one rrset whose content starts with $matchPrefix,
     * keeping the rrset's other values. The dedicated use is dropping an SPF
     * TXT record without destroying other TXT records at the same name
     * (site-verification tokens). Returns true when the op ran.
     */
    public function removeRecordValue(string $zone, string $name, string $type, string $matchPrefix): bool
    {
        $result = app('provisioning')->execute('dns.record.value.delete', [
            'zone' => $zone,
            'name' => self::qualifyName($zone, $name),
            'type' => strtoupper($type),
            'match' => $matchPrefix,
        ]);
        return ($result['status'] ?? '') === 'success';
    }

    public function deleteRecord(string $zone, string $name, string $type): bool
    {
        $result = app('provisioning')->execute('dns.record.delete', [
            'zone' => $zone,
            'name' => self::qualifyName($zone, $name),
            'type' => $type,
        ]);
        return $result['status'] === 'success';
    }

    /**
     * Parse a zone dump into an array of record rows suitable for a table.
     *
     * @return array<int, array{name:string, ttl:int, type:string, content:string}>
     */
    public function parseZoneOutput(string $output): array
    {
        $records = [];
        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '' || str_starts_with($line, '$')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 5);
            if (count($parts) < 4) {
                continue;
            }
            // format: NAME TTL IN TYPE CONTENT
            if ($parts[2] !== 'IN') {
                continue;
            }
            $records[] = [
                'name' => rtrim($parts[0], '.'),
                'ttl' => (int) $parts[1],
                'type' => $parts[3],
                'content' => trim($parts[4]),
            ];
        }
        return $records;
    }
}