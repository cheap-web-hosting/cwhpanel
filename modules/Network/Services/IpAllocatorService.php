<?php
declare(strict_types=1);
namespace Modules\Network\Services;

/**
 * IP address management: deterministic per-account IPv6 from the routed
 * /64, plus a small audited IPv4 pool for dedicated website assignments.
 *
 * Everything is gated on network.ip_management_enabled (default off): with
 * the feature off, no addresses are allocated, no dedicated listen lines
 * are rendered, and no AAAA records are provisioned — the panel behaves
 * exactly like the single-shared-IPv4 model.
 *
 * IPv6 scheme: <prefix>::<hex(0x1000 + account_id)>, e.g. account 1 on
 * 2606:cc0:10:8db::/64 gets ...::1001. Deterministic (stable across
 * reprovision, no counter to desync), sparse (no ND-table pressure),
 * skips ::1 (host). DB unique constraint is the collision backstop.
 */
class IpAllocatorService
{
    public const ENABLED_KEY = 'network.ip_management_enabled';
    public const PREFIX_KEY = 'network.ipv6_prefix';
    public const DEFAULT_PREFIX = '2606:cc0:10:8db::/64';

    public function enabled(): bool
    {
        return trim((string) ($this->setting(self::ENABLED_KEY) ?? '')) === '1';
    }

    /**
     * Free edition: per-account IPv6 is NOT included.
     *
     * The host itself stays dual-stack (nginx/mail keep their [::] listens),
     * but no account gets a dedicated address: allocation is a no-op, so no
     * per-account AAAA records, no dedicated v6 listens and no v6 outbound
     * transports are ever produced. The per-website IPv4 pool is unaffected.
     * Kept as a method (not a constant) so the paid tree can flip it.
     */
    public function perAccountIpv6(): bool
    {
        return false;
    }

    /** Validated per-account IPv6, or null when the feature is off. */
    public function effectiveIpv6(array $account): ?string
    {
        if (!$this->perAccountIpv6()) {
            return null;
        }
        $v6 = trim((string) ($account['ipv6_address'] ?? ''));
        return ($v6 !== '' && filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) ? $v6 : null;
    }

    public function setEnabled(bool $on): void
    {
        $this->putSetting(self::ENABLED_KEY, $on ? '1' : '0');
    }

    /** Routed /64 in CIDR form. Throws on misconfiguration. */
    public function prefix(): string
    {
        $raw = trim((string) ($this->setting(self::PREFIX_KEY) ?? self::DEFAULT_PREFIX));
        if (!preg_match('#^([0-9a-fA-F:]+)/(\d{1,3})$#', $raw, $m)) {
            throw new \RuntimeException("Invalid IPv6 prefix [{$raw}] (expected form 2001:db8::/64).");
        }
        $bits = (int) $m[2];
        if ($bits !== 64 || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \RuntimeException("Invalid IPv6 prefix [{$raw}] (only /64 supported).");
        }
        return strtolower($m[1]) . '/64';
    }

    public function setPrefix(string $cidr): string
    {
        $cidr = trim($cidr);
        if (!preg_match('#^([0-9a-fA-F:]+)/(\d{1,3})$#', $cidr, $m)
            || (int) $m[2] !== 64
            || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
        ) {
            throw new \RuntimeException('Prefix must be a valid IPv6 /64 in CIDR form.');
        }
        $norm = strtolower($m[1]) . '/64';
        $this->putSetting(self::PREFIX_KEY, $norm);
        return $norm;
    }

    /**
     * Allocate (or return the existing) dedicated IPv6 for an account row.
     * Bumps the suffix on collision; binds via the agent before persisting.
     */
    public function allocateForAccount(array $account): string
    {
        if (!$this->perAccountIpv6()) {
            return ''; // free edition: no dedicated IPv6 per account
        }
        if (!$this->enabled()) {
            throw new \RuntimeException('IP management is disabled (Server → Network).');
        }
        if (!empty($account['ipv6_address'])) {
            return (string) $account['ipv6_address'];
        }
        $id = (int) $account['id'];
        $prefix = $this->prefix();
        for ($try = 0; $try < 100; $try++) {
            $addr = $this->candidateFor($id + $try * 100000, $prefix);
            $taken = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `ipv6_address` = ?', [$addr]);
            if ($taken !== null) {
                continue;
            }
            if (!$this->boundLocally($addr)) {
                app('provisioning')->execute('net.ipv6.add', ['address' => $addr]);
            }
            try {
                app('db')->update('accounts', ['ipv6_address' => $addr], '`id` = ?', [$id]);
            } catch (\Throwable $e) {
                try { app('provisioning')->execute('net.ipv6.del', ['address' => $addr]); } catch (\Throwable $ignored) {}
                throw new \RuntimeException('Address already assigned, retry the allocation.');
            }
            audit('network.ipv6.allocated', 'account', $id, ['address' => $addr]);
            return $addr;
        }
        throw new \RuntimeException('No free IPv6 address found in the prefix.');
    }

    /**
     * Release an account's address: unbind + NULL the column. DNS/vhost
     * cleanup is the caller's job (terminate path regenerates after this).
     */
    public function releaseForAccount(array $account): void
    {
        if (!$this->perAccountIpv6()) {
            // Legacy row from a paid/earlier install: drop the value so the
            // vhost/DNS/mail paths stop seeing it. No unbind (never bound here).
            if (trim((string) ($account['ipv6_address'] ?? '')) !== '') {
                app('db')->update('accounts', ['ipv6_address' => null], '`id` = ?', [(int) $account['id']]);
            }
            return;
        }
        $addr = (string) ($account['ipv6_address'] ?? '');
        if ($addr === '') {
            return;
        }
        try {
            app('provisioning')->execute('net.ipv6.del', ['address' => $addr]);
        } catch (\Throwable $e) {
            app('logger')->warning('IPv6 unbind failed for ' . $addr . ': ' . $e->getMessage());
        }
        app('db')->update('accounts', ['ipv6_address' => null], '`id` = ?', [(int) $account['id']]);
        audit('network.ipv6.released', 'account', (int) $account['id'], ['address' => $addr]);
    }

    /**
     * Apply an account's IP state everywhere: regenerate every website
     * vhost (dedicated listens appear/vanish), reprovision DNS (AAAA
     * added/removed, A switched), refresh mail transports. Called after
     * allocate/release and by the backfill. Throws on vhost failure
     * (caller decides); DNS/mail best-effort inside.
     */
    public function applyAccountIps(int $accountId): void
    {
        if (!$this->perAccountIpv6()) {
            return; // free edition: nothing per-account to apply
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }
        $domains = app('db')->select(
            "SELECT * FROM `domains` WHERE `account_id` = ? AND `status` = 'active' ORDER BY `id`",
            [$accountId]
        );
        $dns = app(\Modules\Dns\Services\DnsService::class);
        foreach ($domains as $domain) {
            // Drop stale AAAA first when the account currently has no v6
            // (provision only upserts — it never deletes).
            if (trim((string) ($account['ipv6_address'] ?? '')) === '') {
                $this->removeDomainAaaa($dns, $domain);
            }
            try {
                app(\Modules\Domains\Services\DomainService::class)->provision($account, $domain);
            } catch (\Throwable $e) {
                app('logger')->warning('IP apply provision failed for ' . $domain['name'] . ': ' . $e->getMessage());
            }
        }
        try {
            app(\Modules\Email\Services\MailService::class)->sync();
        } catch (\Throwable $e) {
            app('logger')->warning('IP apply mail sync failed: ' . $e->getMessage());
        }
        audit('network.ips.applied', 'account', $accountId);
    }

    /** Remove every AAAA this panel manages for one domain row. */
    private function removeDomainAaaa(\Modules\Dns\Services\DnsService $dns, array $domain): void
    {
        try {
            if (($domain['type'] ?? '') === 'subdomain' && !empty($domain['parent_domain_id'])) {
                $parent = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) $domain['parent_domain_id']]);
                if ($parent !== null) {
                    $dns->deleteRecord($parent['name'], $domain['name'], 'AAAA');
                    return;
                }
            }
            $zone = (string) $domain['name'];
            $dns->deleteRecord($zone, $zone, 'AAAA');
            $mail = (string) env('MAIL_HOSTNAME', 'mail.') . $zone;
            $dns->deleteRecord($zone, $mail, 'AAAA');
            $dns->deleteRecord($zone, 'webmail.' . $zone, 'AAAA');
        } catch (\Throwable $e) {
            app('logger')->warning('AAAA cleanup failed for ' . ($domain['name'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------- IPv4 pool

    /** @return array<int, array> pool rows, free first */
    public function pool(): array
    {
        try {
            return app('db')->select("SELECT * FROM `ip_pool` WHERE `family` = 'ipv4' ORDER BY `status`, `address`");
        } catch (\Throwable $e) {
            return []; // pre-migration
        }
    }

    /**
     * Add an address to the pool and bind it now (loud failure when the
     * provider hasn't routed it — better than a silent dead entry).
     */
    public function poolAdd(string $address, int $prefixlen = 32, string $note = ''): int
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('IP management is disabled (Server → Network).');
        }
        $address = trim($address);
        if (!$this->isValidIpv4($address)) {
            throw new \RuntimeException('Invalid IPv4 address.');
        }
        if ($prefixlen < 1 || $prefixlen > 32) {
            throw new \RuntimeException('Prefix length must be 1–32.');
        }
        if (app('db')->selectOne('SELECT `id` FROM `ip_pool` WHERE `address` = ?', [$address]) !== null) {
            throw new \RuntimeException('Address is already in the pool.');
        }
        app('provisioning')->execute('net.ipv4.add', ['address' => $address, 'prefixlen' => (string) $prefixlen]);
        $id = (int) app('db')->insert('ip_pool', [
            'address' => $address, 'family' => 'ipv4', 'status' => 'free',
            'website_id' => null, 'note' => mb_substr(trim($note), 0, 255),
            'prefixlen' => $prefixlen,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        audit('network.pool.added', 'ip', $id, ['address' => $address]);
        return $id;
    }

    /** Delete a free pool address (unbinds it). Assigned ones refuse. */
    public function poolDelete(int $id): void
    {
        $row = app('db')->selectOne('SELECT * FROM `ip_pool` WHERE `id` = ?', [$id]);
        if ($row === null) {
            throw new \RuntimeException('Pool entry not found.');
        }
        if (($row['status'] ?? '') !== 'free') {
            throw new \RuntimeException('Address is assigned — unassign the website first.');
        }
        try {
            app('provisioning')->execute('net.ipv4.del', [
                'address' => (string) $row['address'],
                'prefixlen' => (string) ((int) ($row['prefixlen'] ?? 32)),
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('Pool unbind failed for ' . $row['address'] . ': ' . $e->getMessage());
        }
        app('db')->delete('ip_pool', '`id` = ?', [$id]);
        audit('network.pool.deleted', 'ip', $id, ['address' => (string) $row['address']]);
    }

    /**
     * Assign a free pool address to a website (bind if missing, persist,
     * push vhost + DNS). Pass null to unassign back to shared fallback.
     */
    public function assignWebsiteIpv4(int $websiteId, ?string $address): void
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('IP management is disabled (Server → Network).');
        }
        $site = app('db')->selectOne('SELECT * FROM `websites` WHERE `id` = ?', [$websiteId]);
        if ($site === null) {
            throw new \RuntimeException('Website not found.');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $site['account_id']]);
        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) $site['domain_id']]);
        if ($account === null || $domain === null) {
            throw new \RuntimeException('Website has no account or domain.');
        }
        $prevAddress = trim((string) ($site['ipv4_address'] ?? ''));
        $address = $address !== null ? trim($address) : null;
        if ($address === null || $address === '') {
            // Unassign: free the pool row, revert to shared, reprovision.
            if ($prevAddress !== '') {
                $prow = app('db')->selectOne('SELECT * FROM `ip_pool` WHERE `address` = ?', [$prevAddress]);
                if ($prow !== null) {
                    app('db')->update('ip_pool', [
                        'status' => 'free', 'website_id' => null, 'updated_at' => date('Y-m-d H:i:s'),
                    ], '`id` = ?', [(int) $prow['id']]);
                }
                app('db')->update('websites', [
                    'ipv4_address' => null, 'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [$websiteId]);
            }
            app(\Modules\Domains\Services\DomainService::class)->provision($account, $domain);
            audit('network.website.unassigned', 'website', $websiteId);
            return;
        }
        if (!$this->isValidIpv4($address)) {
            throw new \RuntimeException('Invalid IPv4 address.');
        }
        $prow = app('db')->selectOne("SELECT * FROM `ip_pool` WHERE `address` = ? AND `family` = 'ipv4'", [$address]);
        if ($prow === null) {
            throw new \RuntimeException('Address is not in the IPv4 pool.');
        }
        if (($prow['status'] ?? '') !== 'free' && (int) ($prow['website_id'] ?? 0) !== $websiteId) {
            throw new \RuntimeException('Address is already assigned to another website.');
        }
        // Idempotent bind: skip when already on the interface.
        if (!$this->boundLocally($address)) {
            app('provisioning')->execute('net.ipv4.add', [
                'address' => $address,
                'prefixlen' => (string) ((int) ($prow['prefixlen'] ?? 32)),
            ]);
        }
        app('db')->update('ip_pool', [
            'status' => 'assigned', 'website_id' => $websiteId, 'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [(int) $prow['id']]);
        if ($prevAddress !== '' && $prevAddress !== $address) {
            $prev = app('db')->selectOne('SELECT * FROM `ip_pool` WHERE `address` = ?', [$prevAddress]);
            if ($prev !== null) {
                app('db')->update('ip_pool', [
                    'status' => 'free', 'website_id' => null, 'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [(int) $prev['id']]);
            }
        }
        app('db')->update('websites', [
            'ipv4_address' => $address, 'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$websiteId]);
        app(\Modules\Domains\Services\DomainService::class)->provision($account, $domain);
        audit('network.website.assigned', 'website', $websiteId, ['address' => $address]);
    }

    /** True when the address is already bound on this host. */
    public function boundLocally(string $address): bool
    {
        try {
            $r = app('provisioning')->execute('net.addr.list', []);
        } catch (\Throwable $e) {
            return false;
        }
        foreach (explode("\n", (string) ($r['output'] ?? '')) as $line) {
            if (preg_match('/\binet6?\s+(\S+)/', $line, $m)) {
                $have = explode('/', $m[1])[0];
                if (strcasecmp($have, $address) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Backfill: allocate missing dedicated IPv6 for every active account,
     * then apply IP state everywhere (vhosts, DNS, mail). Powers the
     * "allocate missing + apply all" button and converges both directions
     * (enabling, or cleaning up after a disable). Returns per-account
     * results; one account's failure never stops the rest.
     *
     * @return array{allocated:int, applied:int, failed:array<int,string>}
     */
    public function backfill(): array
    {
        if (!$this->perAccountIpv6()) {
            return ['allocated' => 0, 'applied' => 0, 'failed' => [], 'skipped' => 'per-account IPv6 is not included in this edition'];
        }
        if (!$this->enabled()) {
            throw new \RuntimeException('IP management is disabled (Server → Network).');
        }
        $out = ['allocated' => 0, 'applied' => 0, 'failed' => []];
        $accounts = app('db')->select("SELECT * FROM `accounts` WHERE `status` = 'active' ORDER BY `id`");
        foreach ($accounts as $account) {
            $id = (int) $account['id'];
            try {
                if (trim((string) ($account['ipv6_address'] ?? '')) === '') {
                    $fresh = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$id]);
                    if ($fresh !== null) {
                        $this->allocateForAccount($fresh);
                        $out['allocated']++;
                    }
                }
                $this->applyAccountIps($id);
                $out['applied']++;
            } catch (\Throwable $e) {
                $out['failed'][$id] = mb_substr($e->getMessage(), 0, 200);
            }
        }
        audit('network.backfill', null, null, ['allocated' => $out['allocated'], 'applied' => $out['applied'], 'failed' => count($out['failed'])]);
        return $out;
    }

    /** Pure candidate builder (testable without DB/agent). */
    public function candidateFor(int $n, string $prefix): string
    {
        // Double colon: the /64 base contributes 4 groups, the suffix IID
        // one — `base:hex` (single colon) would be 5 groups, unparseable.
        $base = rtrim(explode('/', $prefix)[0], ':');
        return $base . '::' . dechex(0x1000 + $n);
    }

    public function isValidIpv4(string $ip): bool
    {
        return filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public function isValidIpv6(string $ip): bool
    {
        return filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /** True when $ip lies inside the configured /64 (binary prefix compare). */
    public function inPrefix(string $ip, ?string $prefix = null): bool
    {
        $ip = trim($ip);
        if (!$this->isValidIpv6($ip)) {
            return false;
        }
        $base = rtrim(explode('/', $prefix ?? $this->prefix())[0], ':');
        $ipBin = inet_pton($ip);
        $baseBin = inet_pton($base . '::');
        return $ipBin !== false && $baseBin !== false && substr($ipBin, 0, 8) === substr($baseBin, 0, 8);
    }

    private function setting(string $key): ?string
    {
        $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', [$key]);
        return $row ? (string) $row['value'] : null;
    }

    private function putSetting(string $key, string $value): void
    {
        $db = app('db');
        if ($db->selectOne('SELECT `key` FROM `settings` WHERE `key` = ?', [$key])) {
            $db->update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`key` = ?', [$key]);
        } else {
            $db->insert('settings', ['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }
}
