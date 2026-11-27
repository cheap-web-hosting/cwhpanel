<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * Server hostname management (admin → Server → Hostname).
 *
 * Changing the hostname cascades through everything that depends on it:
 *   1. system hostname (/etc/hostname + /etc/hosts) via the agent
 *   2. the local PowerDNS zone + records for the hostname's domain
 *      (A/AAAA, mail A/AAAA, MX, SPF, DMARC, DKIM, NS) — so host mail
 *      authenticates without an external DNS provider
 *   3. pDNS the panel's own zone row (so it lists under DNS)
 *   4. postfix mail identity (myhostname/myorigin/helo) so the envelope
 *      domain matches the SPF/DKIM records
 *   5. TLS: the shared provision-hostname-tls.php issues a Let's Encrypt
 *      certificate and applies it to nginx (panel vhost), postfix, dovecot
 *      and vsftpd, and rewrites APP_URL
 *
 * Runs as an async job (`system.hostname.change`); returns a step summary.
 */
class HostnameService
{
    public const CERT_CHAIN = '/etc/cwhpanel/ssl/hostname/fullchain.pem';
    public const CERT_KEY = '/etc/cwhpanel/ssl/hostname/privkey.pem';
    public const PANEL_CONF = '/etc/nginx/conf.d/panel.conf';

    /** Static hostname (canonical, from /etc/hostname) or the runtime one. */
    public function current(): string
    {
        $f = @file_get_contents('/etc/hostname');
        if ($f !== false) {
            $h = strtolower(trim($f));
            if ($h !== '') {
                return $h;
            }
        }
        return strtolower(trim((string) gethostname()));
    }

    /** Registrable/parent domain of an FQDN: jarvis.host.tld → host.tld. */
    public function parentDomain(string $fqdn): string
    {
        $labels = explode('.', $fqdn);
        return count($labels) >= 3 ? implode('.', array_slice($labels, 1)) : $fqdn;
    }

    /** @throws \RuntimeException on an invalid FQDN. */
    public function validateFqdn(string $fqdn): string
    {
        $fqdn = strtolower(trim($fqdn));
        if (preg_match('/^(?=.{4,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $fqdn) !== 1) {
            throw new \RuntimeException('Enter a valid fully-qualified hostname (e.g. panel.example.com).');
        }
        foreach (['.local', '.localhost', '.internal', '.invalid', '.test', '.example'] as $bad) {
            if (str_ends_with($fqdn, $bad)) {
                throw new \RuntimeException('That domain suffix cannot be used for a public hostname.');
            }
        }
        return $fqdn;
    }

    public function publicIpv4(): string
    {
        $ip = trim((string) env('PANEL_PUBLIC_IP', ''));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        // Fallback: first global IPv4 on the box.
        foreach ($this->localGlobalIps() as $cand) {
            if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $cand;
            }
        }
        throw new \RuntimeException('No public IPv4 known (set PANEL_PUBLIC_IP).');
    }

    public function hostIpv6(): ?string
    {
        foreach ($this->localGlobalIps() as $cand) {
            if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return strtolower($cand);
            }
        }
        return null;
    }

    /** @return array<int,string> global-scope addresses on the host */
    private function localGlobalIps(): array
    {
        $ips = [];
        $out = [];
        // exec() returns the LAST OUTPUT LINE, never the exit status; compare
        // the third argument instead (the old `=== 0` check made this method
        // always return an empty list).
        $rc = 1;
        @exec('ip -o addr show scope global 2>/dev/null', $out, $rc);
        if ($rc === 0) {
            foreach ($out as $line) {
                if (preg_match('/\binet6?\s+([0-9a-fA-F:.]+)\//', $line, $m)) {
                    $ips[] = strtolower($m[1]);
                }
            }
        }
        if ($ips === []) {
            $h = [];
            $rc = 1;
            @exec('hostname -I 2>/dev/null', $h, $rc);
            if ($rc === 0 && isset($h[0])) {
                foreach (preg_split('/\s+/', trim($h[0])) ?: [] as $ip) {
                    if ($ip !== '') {
                        $ips[] = strtolower($ip);
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /** Installed hostname cert: [cn, sans[], expires, days] or null. */
    public function certInfo(): ?array
    {
        if (!is_file(self::CERT_CHAIN)) {
            return null;
        }
        $pem = @file_get_contents(self::CERT_CHAIN);
        $info = $pem !== false ? @openssl_x509_parse($pem) : false;
        if ($info === false) {
            return null;
        }
        $sans = [];
        if (!empty($info['extensions']['subjectAltName'])) {
            foreach (explode(',', $info['extensions']['subjectAltName']) as $part) {
                $part = trim($part);
                if (str_starts_with($part, 'DNS:')) {
                    $sans[] = strtolower(substr($part, 4));
                }
            }
        }
        $expires = (int) ($info['validTo_time_t'] ?? 0);
        return [
            'cn' => strtolower((string) ($info['subject']['CN'] ?? '')),
            'sans' => $sans,
            'expires' => $expires,
            'days' => (int) floor(($expires - time()) / 86400),
        ];
    }

    /** Resolve the hostname and report whether it points at this server. */
    public function dnsState(string $fqdn): array
    {
        $addrs = [];
        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $t => $flag) {
            foreach (@dns_get_record($fqdn, $flag) ?: [] as $r) {
                $ip = strtolower((string) ($r['ip'] ?? $r['ipv6'] ?? ''));
                if ($ip !== '') {
                    $addrs[] = $ip;
                }
            }
        }
        if ($addrs === []) {
            return ['state' => 'none', 'addrs' => []];
        }
        $local = $this->localGlobalIps();
        foreach ($addrs as $ip) {
            if (in_array($ip, $local, true)) {
                return ['state' => 'direct', 'addrs' => array_values(array_unique($addrs))];
            }
        }
        return ['state' => 'remote', 'addrs' => array_values(array_unique($addrs))];
    }

    /**
     * Ensure the local PowerDNS zone + host records exist for the hostname.
     *
     * @return array<int,string> human-readable record lines written
     */
    public function provisionDns(string $fqdn): array
    {
        if (!module_enabled('dns')) {
            throw new \RuntimeException(
                'DNS records skipped: the Dns module is disabled (admin → Settings → Modules).'
            );
        }

        $zone = $this->parentDomain($fqdn);
        $labels = explode('.', $fqdn);
        $hostPrefix = count($labels) >= 3 ? $labels[0] : '';   // '' = apex
        $hostName = $hostPrefix === '' ? $zone : $hostPrefix . '.' . $zone;

        $ns = (string) env('DNS_NAMESERVER', 'ns1.' . $zone);
        $ns2 = (string) env('DNS_NAMESERVER2', '');
        $selector = (string) env('DKIM_SELECTOR', 'default');
        $ip4 = $this->publicIpv4();
        $ip6 = $this->hostIpv6();
        $mail = 'mail.' . $zone;

        /** @var \Modules\Dns\Services\DnsService $dns */
        $dns = app(\Modules\Dns\Services\DnsService::class);

        $written = [];
        try {
            $dns->createZone($zone, $ns);
            $written[] = "zone {$zone} (NS {$ns})";
        } catch (\Throwable $e) {
            // Already exists is fine — records below are idempotent replace-rrset.
            $written[] = "zone {$zone} (exists)";
        }

        $dns->upsertRecord($zone, $hostName, 'A', $ip4);
        $written[] = "{$hostName} A {$ip4}";
        if ($ip6 !== null) {
            $dns->upsertRecord($zone, $hostName, 'AAAA', $ip6);
            $written[] = "{$hostName} AAAA {$ip6}";
        }

        $dns->upsertRecord($zone, $mail, 'A', $ip4);
        $written[] = "{$mail} A {$ip4}";
        if ($ip6 !== null) {
            $dns->upsertRecord($zone, $mail, 'AAAA', $ip6);
            $written[] = "{$mail} AAAA {$ip6}";
        }
        $dns->upsertRecord($zone, $zone, 'MX', '10 ' . $mail);
        $written[] = "{$zone} MX 10 {$mail}";

        $dns->upsertRecord($zone, $zone, 'TXT', '"v=spf1 mx a -all"');
        $written[] = "{$zone} TXT SPF";
        $dns->upsertRecord($zone, '_dmarc.' . $zone, 'TXT', '"v=DMARC1; p=none; rua=mailto:postmaster@' . $zone . '"');
        $written[] = "_dmarc.{$zone} TXT DMARC";

        // DKIM: publish the public key, store the private key for signing.
        $dkim = $this->generateDkim($zone);
        if ($dkim !== null) {
            $dns->upsertRecord($zone, $selector . '._domainkey.' . $zone, 'TXT', '"v=DKIM1; k=rsa; p=' . $dkim['pub'] . '"');
            $written[] = "{$selector}._domainkey.{$zone} TXT DKIM";
        }

        if ($ns2 !== '' && $ns2 !== $ns) {
            $dns->upsertRecord($zone, $zone, 'NS', $ns2);
            $written[] = "{$zone} NS {$ns2}";
        }

        // The host zone is server-level, not a customer's — it lives in pDNS
        // only (no `dns_zones` row, which would surface it under an account).
        $this->rebuildOpendkimTables();
        return $written;
    }

    /**
     * Rebuild opendkim's KeyTable + SigningTable from /etc/cwhpanel/dkim/*.priv
     * and reload the milter. Called after any DKIM key is provisioned (host
     * DNS seeding, customer domain creation).
     */
    public static function rebuildOpendkimTables(): void
    {
        $keyTable = '';
        $signingTable = '';
        $selector = (string) env('DKIM_SELECTOR', 'default');
        $glob = glob('/etc/cwhpanel/dkim/*.priv') ?: [];
        foreach ($glob as $path) {
            $domain = basename((string) $path, '.priv');
            if ($domain !== '' && preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
                $keyTable .= "{$selector}._domainkey.{$domain} {$domain}:{$selector}:{$path}\n";
                $signingTable .= "*@{$domain} {$selector}._domainkey.{$domain}\n";
            }
        }
        try {
            app('provisioning')->execute('dkim.signing.sync', [
                'key_table' => $keyTable,
                'signing_table' => $signingTable,
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('opendkim signing-table sync failed: ' . $e->getMessage());
        }
        // Also ensure the opendkim key dir is always accessible.
        @chmod('/etc/cwhpanel/dkim', 0755);
    }

    /**
     * Generate a DKIM keypair, publish/store it. Returns ['pub'=>b64der|null]
     * or null on failure.
     *
     * @return array{pub:string}|null
     */
    private function generateDkim(string $zone): ?array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            return null;
        }
        if (!openssl_pkey_export($key, $privatePem)) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key'])) {
            return null;
        }
        $der = base64_decode((string) preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s/', '', (string) $details['key']));
        $pub = rtrim(base64_encode((string) $der), '=');

        try {
            app('provisioning')->execute('dkim.key.write', ['domain' => $zone, 'private_key' => (string) $privatePem]);
        } catch (\Throwable $e) {
            app('logger')->warning('Hostname DKIM key store failed for ' . $zone . ': ' . $e->getMessage());
        }
        return ['pub' => $pub];
    }

    /**
     * Run the shared TLS provisioner for the hostname. Returns [ok, output].
     *
     * @return array{ok:bool, output:string}
     */
    public function provisionTls(string $fqdn, bool $force = true): array
    {
        $script = BASE_PATH . '/scripts/provision-hostname-tls.php';
        if (!is_file($script)) {
            return ['ok' => false, 'output' => 'provisioner script not found'];
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' --hostname=' . escapeshellarg($fqdn)
            . ($force ? ' --force' : '');
        $out = [];
        $code = 0;
        @exec($cmd . ' 2>&1', $out, $code);
        return ['ok' => $code === 0, 'output' => trim(implode("\n", $out))];
    }

    /**
     * Full cascade. Returns a step summary (never throws for the TLS step).
     *
     * @return array{fqdn:string, steps:array<int,array{step:string, ok:bool, detail:string}>}
     */
    public function change(string $fqdn): array
    {
        $fqdn = $this->validateFqdn($fqdn);
        $zone = $this->parentDomain($fqdn);
        $ip4 = $this->publicIpv4();
        $steps = [];

        // 1. system hostname + /etc/hosts
        try {
            $r = app('provisioning')->execute('system.hostname.set', ['fqdn' => $fqdn, 'ip' => $ip4]);
            $steps[] = ['step' => 'System hostname', 'ok' => true, 'detail' => trim((string) ($r['output'] ?? 'set'))];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'System hostname', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // 2. DNS (zone + records incl. SPF/DKIM/DMARC)
        try {
            $recs = $this->provisionDns($fqdn);
            $steps[] = ['step' => 'DNS records', 'ok' => true, 'detail' => implode('; ', $recs)];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'DNS records', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // 3. postfix mail identity (envelope domain must match SPF/DKIM)
        try {
            $r = app('provisioning')->execute('mail.hostname.set', ['fqdn' => $fqdn, 'origin' => $zone]);
            $steps[] = ['step' => 'Mail identity', 'ok' => true, 'detail' => "myhostname={$fqdn} myorigin={$zone}"];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'Mail identity', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // 4. panel vhost server_name
        try {
            $this->updatePanelServerName($fqdn);
            $steps[] = ['step' => 'Panel vhost', 'ok' => true, 'detail' => "server_name {$fqdn}"];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'Panel vhost', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // 5. TLS + service certs + APP_URL (may fail until DNS points here)
        $tls = $this->provisionTls($fqdn, true);
        $steps[] = [
            'step' => 'TLS certificate + services',
            'ok' => $tls['ok'],
            'detail' => $tls['ok']
                ? 'issued + applied to nginx/postfix/dovecot/vsftpd; APP_URL updated'
                : 'failed (DNS must resolve here for Let\'s Encrypt): ' . $tls['output'],
        ];

        return ['fqdn' => $fqdn, 'steps' => $steps];
    }

    /** Point the panel vhost's server_name at the hostname (idempotent). */
    private function updatePanelServerName(string $fqdn): void
    {
        $conf = @file_get_contents(self::PANEL_CONF);
        if ($conf === false) {
            throw new \RuntimeException('cannot read ' . self::PANEL_CONF);
        }
        $new = preg_replace('/^(\s*)server_name\s+[^;]+;/m', '${1}server_name ' . $fqdn . ';', $conf, 1, $count);
        if ($new === null) {
            throw new \RuntimeException('server_name rewrite failed');
        }
        if ($new !== $conf) {
            copy(self::PANEL_CONF, self::PANEL_CONF . '.bak-' . date('Ymd-His'));
            file_put_contents(self::PANEL_CONF, $new, LOCK_EX);
            $out = [];
            if (@exec('/usr/sbin/nginx -t 2>&1', $out) !== 0) {
                file_put_contents(self::PANEL_CONF, $conf, LOCK_EX); // rollback
                throw new \RuntimeException('nginx -t failed: ' . end($out));
            }
            @exec('systemctl reload nginx 2>/dev/null');
        }
    }
}