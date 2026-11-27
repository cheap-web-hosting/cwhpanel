<?php
declare(strict_types=1);
namespace Modules\Domains\Services;

use Modules\Core\Services\NginxIsolationService;
use Modules\Websites\Services\NginxConfigGenerator;
use Modules\Websites\Services\VhostWriter;

/**
 * Domain provisioning logic for the four hosting-domain types:
 *
 *   main      — primary domain, served from /home/<user>/public_html
 *   addon     — additional domain, served from its own /home/<user>/<domain>/
 *   subdomain — child of a domain, served from the parent's docroot/<sub>/
 *   parked    — alias of the primary domain (same content, no folder), or a
 *               redirect when a redirect_url is set
 *
 * Each domain gets, as appropriate: a document-root directory on disk, a DNS
 * zone/record, and an nginx vhost (website).
 */
class DomainService
{
    public const TYPES = ['main', 'addon', 'parked', 'subdomain'];

    private static function home(array $account): string
    {
        return $account['home_dir'] ?? '/home/' . $account['username'];
    }

    /**
     * RFC-1123-style label validation: lowercase alnum, optional interior
     * hyphens, must start/end with alnum, max 63 chars. Rejects interior
     * empty labels ("a..b"), label-edge hyphens ("a-.b"), and over-long
     * labels (vhost name cap mismatch).
     */
    public static function validLabel(string $label): bool
    {
        if ($label === '' || strlen($label) > 63) {
            return false;
        }
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label);
    }

    /**
     * Full hostname validation: every dot-separated label must be a valid
     * label and the total length must not exceed 253.
     */
    public static function validDomain(string $name): bool
    {
        $name = strtolower(trim($name));
        if ($name === '' || strlen($name) > 253) {
            return false;
        }
        foreach (explode('.', $name) as $label) {
            if (!self::validLabel($label)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Nameserver hostname (ns1.example.com). Rejects IP literals and
     * single-label names, normalises case and the trailing root dot.
     */
    public static function normalizeNameserver(?string $ns): ?string
    {
        if ($ns === null) {
            return null;
        }
        $ns = strtolower(trim($ns));
        $ns = rtrim($ns, '.');
        if ($ns === '') {
            return null;
        }
        if (filter_var($ns, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        if (substr_count($ns, '.') < 1 || !self::validDomain($ns)) {
            return null;
        }
        return $ns;
    }

    /**
     * A parked-domain redirect target must be http(s) to a PUBLIC host.
     * Scheme-less "//host" (open redirect) and loopback/private/reserved
     * hosts (internal panel hops, metadata) are refused. This mirrors the
     * agent-side nginx pass validation: internal hosts are never a valid
     * redirect target from a customer-controlled domain.
     */
    public static function validRedirectUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        $host = strtolower(rtrim($host, '.'));
        if ($host === 'localhost' || $host === '0.0.0.0' || str_ends_with($host, '.localhost')) {
            return false;
        }
        return true;
    }

    /**
     * Compute the document root for a domain given its type and (for
     * subdomains) its parent's document root.
     */
    public static function documentRoot(array $account, string $type, string $name, ?string $parentDocRoot = null): string
    {
        $home = self::home($account);

        switch ($type) {
            case 'addon':
                return $home . '/' . strtolower($name) . '/public_html';
            case 'subdomain':
                $sub = strtolower(explode('.', $name, 2)[0]);
                return rtrim($parentDocRoot ?? ($home . '/public_html'), '/') . '/' . $sub;
            case 'parked':
            case 'main':
            default:
                return $home . '/public_html';
        }
    }

    /**
     * Create the on-disk document root (when the type has its own folder) and
     * set its ownership to the account's system user.
     */
    private function ensureDocumentRoot(array $account, string $documentRoot): void
    {
        $ops = app('provisioning');
        $ops->execute('directory.create', ['path' => $documentRoot]);
        $ops->execute('directory.chown', ['owner' => $account['username'], 'path' => $documentRoot]);
        $ops->execute('selinux.restorecon', ['path' => $documentRoot]);
        // A fresh site with no index answers 403 (directory index forbidden),
        // which reads as broken. Drop a placeholder only when the dir is
        // empty — never overwrite real content. FileService handles the
        // account-relative paths and base64 write encoding.
        try {
            $prefix = '/home/' . $account['username'] . '/';
            if (str_starts_with($documentRoot, $prefix)) {
                $rel = substr($documentRoot, strlen($prefix));
                $fs = app(\Modules\Files\Services\FileService::class);
                $entries = $fs->list($account['username'], $rel);
                if (($entries['entries'] ?? null) === []) {
                    $name = preg_replace('/[^a-z0-9.-]/i', '', $account['username']);
                    $fs->write(
                        $account['username'],
                        ($rel === '' ? '' : $rel . '/') . 'index.html',
                        "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Welcome</title></head>\n<body style=\"font-family:sans-serif;max-width:640px;margin:4em auto;text-align:center\">\n<h1>Welcome to {$name}</h1>\n<p>This site was just created in the control panel and is ready for content.</p>\n</body></html>\n"
                    );
                }
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Placeholder index skipped for ' . $documentRoot . ': ' . $e->getMessage());
        }
    }

    /**
     * Create DNS for a domain: the necessary zone + standard records.
     *
     * main/addon/parked each get their own zone with NS + SOA (via create-zone),
     * an A record for the apex, a www CNAME, a mail A record (webmail), and an
     * MX record for inbound mail. A subdomain gets an A record in its parent zone.
     */
    /**
     * Effective IPs for DNS: account dedicated IPv6 (or null) + the website
     * dedicated IPv4 when a website row already carries one (first-time
     * provisions have none yet — assignment needs the website to exist —
     * so they correctly fall back to shared). Empty unless IP management
     * is enabled: disabled means legacy single-IPv4 behavior, byte for byte.
     *
     * @return array{v6:?string, v4:?string}
     */
    private function effectiveDnsIps(array $account, array $domain): array
    {
        $none = ['v6' => null, 'v4' => null];
        try {
            if (!app(\Modules\Network\Services\IpAllocatorService::class)->enabled()) {
                return $none;
            }
        } catch (\Throwable $e) {
            app('logger')->warning('effectiveDnsIps allocator check failed: ' . $e->getMessage());
            return $none;
        }
        // Free edition: per-account IPv6 is not part of this edition, so the
        // allocator returns null and no AAAA records are ever published.
        $v6 = app(\Modules\Network\Services\IpAllocatorService::class)->effectiveIpv6($account);
        $v4 = null;
        try {
            $site = app('db')->selectOne(
                'SELECT `ipv4_address` FROM `websites`
                 WHERE `account_id` = ? AND `domain_id` = ? AND `status` = ?
                 ORDER BY `updated_at` DESC, `id` DESC LIMIT 1',
                [(int) $account['id'], (int) $domain['id'], 'active']
            );
            $candidate = trim((string) ($site['ipv4_address'] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $v4 = $candidate;
            }
        } catch (\Throwable $e) {
            app('logger')->warning('effectiveDnsIps ipv4 lookup failed: ' . $e->getMessage());
        }
        return ['v6' => $v6, 'v4' => $v4];
    }

    private function provisionDns(array $account, array $domain, ?array $parent = null): void
    {
        if (!module_enabled('dns')) {
            app('logger')->warning(
                'DNS skipped for ' . $domain['name'] . ': the Dns module is disabled (admin → Settings → Modules).'
            );
            return;
        }

        $dns = app(\Modules\Dns\Services\DnsService::class);
        // Nameservers are a SERVER setting (admin → DNS → Nameservers) and apply
        // to every hosted zone. When none are configured the panel does not
        // host DNS: a zone without an NS rrset is REFUSED by PowerDNS, so
        // creating one would be junk (the domain still gets its web vhost).
        [$ns1, $ns2] = \Modules\Dns\Services\DnsSettings::get();
        if ($ns1 === null) {
            app('logger')->info('DNS skipped for ' . $domain['name'] . ': no server nameservers are configured (admin → DNS → Nameservers).');
            return;
        }
        // Never fall back to a hardcoded address: use the configured public
        // IPv4 (env) or the first global IPv4 on this box. Without one, DNS
        // records cannot be published — say so instead of writing a stale IP.
        try {
            $serverIp = app(\Modules\Core\Services\HostnameService::class)->publicIpv4();
        } catch (\Throwable $e) {
            app('logger')->warning('DNS skipped for ' . $domain['name'] . ': ' . $e->getMessage());
            return;
        }
        $mailPrefix = \Modules\Dns\Services\DnsSettings::mailHostnamePrefix();
        $ips = $this->effectiveDnsIps($account, $domain);
        $webIp = $ips['v4'] ?? $serverIp;
        [$mailMode, $spfExtra, $dmarcPolicy, $dmarcRua, $webmailRecord, $ttl] = self::mailDnsProfile($domain);

        if ($domain['type'] === 'subdomain' && $parent !== null) {
            // A record in the parent zone: sub IN A <ip>. The subdomain shares
            // its parent's zone, so no new dns_zones row is created. Create the
            // parent zone when it is missing (domain rows can exist without a
            // zone yet — e.g. a freshly added addon domain whose zone creation
            // was skipped); upsertRecord throws "Zone does not exist" otherwise.
            try {
                $dns->upsertRecord($parent['name'], $domain['name'], 'A', $webIp, $ttl);
            } catch (\Throwable $e) {
                // Missing parent zone: create it with the server nameservers.
                $dns->createZone($parent['name'], $ns1, $ns2);
                $dns->upsertRecord($parent['name'], $domain['name'], 'A', $webIp, $ttl);
            }
            if ($ips['v6'] !== null) {
                $dns->upsertRecord($parent['name'], $domain['name'], 'AAAA', $ips['v6'], $ttl);
            }
            return;
        }

        $zone = $domain['name'];
        $mail = $mailPrefix . $zone;   // e.g. mail.hostddl.com

        // Create the zone with the server nameservers (SOA + NS).
        // Ignore "already exists" errors.
        try {
            $dns->createZone($zone, $ns1, $ns2);
        } catch (\Throwable $e) {
            // Zone may already exist; records below are idempotent replace-rrset.
        }

        // Web (dedicated v4 when assigned, else the shared fallback; www
        // stays a CNAME so it inherits whichever address family is live).
        $dns->upsertRecord($zone, $zone, 'A', $webIp, $ttl);
        $dns->upsertRecord($zone, 'www.' . $zone, 'CNAME', $zone, $ttl);
        if ($ips['v6'] !== null) {
            $dns->upsertRecord($zone, $zone, 'AAAA', $ips['v6'], $ttl);
        }

        // Mail records only when THIS server hosts the domain's mail. With
        // "external" the customer's provider publishes MX/mail/DKIM itself.
        if ($mailMode === 'local') {
            $dns->upsertRecord($zone, $mail, 'A', $serverIp, $ttl);
            if ($ips['v6'] !== null) {
                $dns->upsertRecord($zone, $mail, 'AAAA', $ips['v6'], $ttl);
            }
            $dns->upsertRecord($zone, $zone, 'MX', '10 ' . $mail, $ttl);
        }

        // Webmail front door (opt-out: some customers host their own).
        if ($webmailRecord) {
            $dns->upsertRecord($zone, 'webmail.' . $zone, 'A', $serverIp, $ttl);
            if ($ips['v6'] !== null) {
                $dns->upsertRecord($zone, 'webmail.' . $zone, 'AAAA', $ips['v6'], $ttl);
            }
        }

        // SPF: local mail publishes "mx a"; external mail publishes only the
        // mechanisms the customer supplied (their provider's includes). With
        // external mail and no mechanisms no SPF is written at all.
        $spfParts = [];
        if ($mailMode === 'local') {
            $spfParts[] = 'mx';
            $spfParts[] = 'a';
        }
        if ($spfExtra !== '') {
            $spfParts[] = $spfExtra;
        }
        if ($spfParts !== []) {
            $dns->upsertRecord($zone, $zone, 'TXT', '"v=spf1 ' . implode(' ', $spfParts) . ' -all"', $ttl);
        }

        // DMARC applies regardless of where mail is hosted — the customer owns
        // the policy and the report address.
        $rua = $dmarcRua !== '' ? $dmarcRua : 'postmaster@' . $zone;
        $dns->upsertRecord($zone, '_dmarc.' . $zone, 'TXT', '"v=DMARC1; p=' . $dmarcPolicy . '; rua=mailto:' . $rua . '"', $ttl);

        if ($mailMode === 'local') {
            $this->provisionDkim($dns, $zone, $ttl);
        }

        // Record the zone in the panel's own DB so it appears on the customer's
        // "DNS Zones" page (the panel table is the source of that list).
        if (app('db')->selectOne('SELECT `id` FROM `dns_zones` WHERE `name` = ?', [$zone]) === null) {
            app('db')->insert('dns_zones', [
                'account_id' => (int) $account['id'],
                'name' => $zone,
                'nameserver' => $ns1,
                'nameserver2' => $ns2,
                'dnssec' => 0,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Normalised mail/DNS profile for a domain row.
     *
     * @return array{0:string,1:string,2:string,3:string,4:bool,5:int}
     *         [mail_mode, spf_extra, dmarc_policy, dmarc_rua, webmail_record, ttl]
     */
    public static function mailDnsProfile(array $domain): array
    {
        $mode = ((string) ($domain['mail_mode'] ?? 'local')) === 'external' ? 'external' : 'local';

        $spf = trim((string) ($domain['spf_extra'] ?? ''));
        // Mechanisms only: letters, digits, and the characters SPF uses
        // (include:_spf.x.com, ip4:1.2.3.4/32, a:host, ~all is NOT allowed —
        // the terminal "-all" is added by the panel).
        if ($spf !== '' && preg_match('/^[a-z0-9]([a-z0-9:._\/+\- ]{0,253})$/i', $spf) !== 1) {
            $spf = '';
        }

        $policy = strtolower(trim((string) ($domain['dmarc_policy'] ?? '')));
        if (!in_array($policy, ['none', 'quarantine', 'reject'], true)) {
            $policy = 'none';
        }

        $rua = trim((string) ($domain['dmarc_rua'] ?? ''));
        if ($rua !== '' && filter_var($rua, FILTER_VALIDATE_EMAIL) === false) {
            $rua = '';
        }

        $webmail = !array_key_exists('webmail_record', $domain) || (int) $domain['webmail_record'] === 1;

        $ttl = (int) ($domain['dns_ttl'] ?? 0);
        if ($ttl < 60 || $ttl > 604800) {
            $ttl = 3600;
        }

        return [$mode, $spf, $policy, $rua, $webmail, $ttl];
    }

    /**
     * Validate the mail/DNS profile fields coming from a form.
     *
     * @return array{mail_mode:string,spf_extra:?string,dmarc_policy:string,dmarc_rua:?string,webmail_record:int,dns_ttl:?int}
     * @throws \RuntimeException with a user-facing message
     */
    public static function validateMailDnsInput(array $in): array
    {
        $mode = strtolower(trim((string) ($in['mail_mode'] ?? 'local')));
        if (!in_array($mode, ['local', 'external'], true)) {
            throw new \RuntimeException('Mail hosting must be either "hosted here" or "external".');
        }

        $spf = trim((string) ($in['spf_extra'] ?? ''));
        if ($spf !== '') {
            if (strlen($spf) > 250 || preg_match('/^[a-z0-9]([a-z0-9:._\/+\- ]{0,249})$/i', $spf) !== 1 || str_contains($spf, '"')) {
                throw new \RuntimeException('SPF extra mechanisms may only contain SPF tokens (e.g. include:_spf.google.com).');
            }
        }

        $policy = strtolower(trim((string) ($in['dmarc_policy'] ?? 'none')));
        if ($policy === '') {
            $policy = 'none';
        }
        if (!in_array($policy, ['none', 'quarantine', 'reject'], true)) {
            throw new \RuntimeException('DMARC policy must be none, quarantine or reject.');
        }

        $rua = trim((string) ($in['dmarc_rua'] ?? ''));
        if ($rua !== '' && filter_var($rua, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('DMARC report address must be an email address (or empty for postmaster@<domain>).');
        }

        $webmail = isset($in['webmail_record']) && (string) $in['webmail_record'] !== '0' ? 1 : 0;

        $ttlRaw = trim((string) ($in['dns_ttl'] ?? ''));
        $ttl = null;
        if ($ttlRaw !== '') {
            if (!ctype_digit($ttlRaw) || (int) $ttlRaw < 60 || (int) $ttlRaw > 604800) {
                throw new \RuntimeException('TTL must be a number of seconds between 60 and 604800.');
            }
            $ttl = (int) $ttlRaw;
        }

        return [
            'mail_mode' => $mode,
            'spf_extra' => $spf !== '' ? $spf : null,
            'dmarc_policy' => $policy,
            'dmarc_rua' => $rua !== '' ? $rua : null,
            'webmail_record' => $webmail,
            'dns_ttl' => $ttl,
        ];
    }

    /**
     * Re-run DNS provisioning for an existing domain (respecting its profile).
     * Public entry point for profile changes; resolves the parent for
     * subdomains so the A/AAAA lands in the parent zone.
     */
    public function refreshDns(array $account, array $domain): void
    {
        $parent = null;
        if (($domain['type'] ?? '') === 'subdomain' && !empty($domain['parent_domain_id'])) {
            $parent = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) $domain['parent_domain_id']]) ?: null;
        }
        $this->provisionDns($account, $domain, $parent);
    }

    /**
     * Apply a changed mail/DNS profile: remove the records the new profile no
     * longer publishes, then re-provision.
     *
     * Only dedicated names are removed (MX, mail.<d>, webmail.<d>, the DKIM
     * selector). The apex TXT rrset is deliberately NOT touched — it may hold
     * verification tokens, and pdnsutil has no per-value delete; when the
     * customer supplies SPF mechanisms the record is replaced in place.
     */
    public function applyMailDnsProfile(array $account, array $domain): void
    {
        if (($domain['type'] ?? '') === 'subdomain') {
            $this->refreshDns($account, $domain);
            return;
        }

        $zone = (string) $domain['name'];
        [$mode, $spf, , , $webmail] = self::mailDnsProfile($domain);
        $dns = app(\Modules\Dns\Services\DnsService::class);
        $mailHost = \Modules\Dns\Services\DnsSettings::mailHostnamePrefix() . $zone;

        // External mail with no mechanisms means "no SPF": drop the SPF value
        // from the apex TXT rrset WITHOUT touching other TXT records there
        // (verification tokens). pdnsutil has no per-value delete, so the
        // agent rewrites the rrset with the remaining values.
        if ($mode === 'external') {
            try {
                $dns->removeRecordValue($zone, $zone, 'TXT', 'v=spf1');
            } catch (\Throwable $e) {
                app('logger')->warning('SPF removal failed for ' . $zone . ': ' . $e->getMessage());
            }
        }

        $removals = [];
        if ($mode === 'external') {
            $removals[] = [$zone, 'MX'];
            $removals[] = [$mailHost, 'A'];
            $removals[] = [$mailHost, 'AAAA'];
            $removals[] = [\Modules\Dns\Services\DnsSettings::dkimSelector() . '._domainkey.' . $zone, 'TXT'];
        }
        if (!$webmail) {
            $removals[] = ['webmail.' . $zone, 'A'];
            $removals[] = ['webmail.' . $zone, 'AAAA'];
        }

        foreach ($removals as [$name, $type]) {
            try {
                $dns->deleteRecord($zone, $name, $type);
            } catch (\Throwable $e) {
                // Not published (fresh domain) — nothing to remove.
            }
        }

        $this->refreshDns($account, $domain);
    }

    /**
     * Generate a DKIM keypair, publish the public key as a default._domainkey
     * TXT record, and store the private key for outbound signing.
     */
    private function provisionDkim($dns, string $zone, int $ttl = 3600): void
    {
        $selector = \Modules\Dns\Services\DnsSettings::dkimSelector();
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            return;
        }

        openssl_pkey_export($key, $privatePem);

        // Extract the public key in PEM, convert to DER, and base64-encode it.
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key'])) {
            return;
        }
        $pubPem = (string) $details['key'];
        $der = base64_decode(preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s/', '', $pubPem));
        $pub = rtrim(base64_encode($der), '='); // standard base64, drop padding

        // Store the private key for future OpenDKIM/opendkim signing. Written
        // via the agent (root) so it works from the panel user and gets
        // root:root 0600 + the right context (a direct panel write silently
        // fails on a root-owned /etc/cwhpanel and would be SELinux-denied).
        try {
            app('provisioning')->execute('dkim.key.write', [
                'domain' => $zone,
                'private_key' => (string) $privatePem,
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('DKIM key store failed for ' . $zone . ': ' . $e->getMessage());
        }

        $dns->upsertRecord($zone, $selector . '._domainkey.' . $zone, 'TXT', '"v=DKIM1; k=rsa; p=' . $pub . '"', $ttl);
        \Modules\Core\Services\HostnameService::rebuildOpendkimTables();
    }

    /**
     * Build the nginx vhost (server block) for a domain.
     */
    private function nginxConfig(array $account, array $domain, string $documentRoot, ?array $parent = null): string
    {
        $username = $account['username'];
        $serverName = $domain['name'];

        if ($domain['type'] === 'parked' && !empty($domain['redirect_url'])) {
            $target = preg_replace('/[^A-Za-z0-9:\/\.\-]/', '', (string) $domain['redirect_url']);
            $extra = '';
            try {
                if (app(\Modules\Network\Services\IpAllocatorService::class)->enabled()) {
                    $v6 = trim((string) ($account['ipv6_address'] ?? ''));
                    if ($v6 !== '' && preg_match('/^[0-9a-fA-F:]+$/', $v6) && filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $extra = "    listen [{$v6}]:80;\n";
                    }
                }
            } catch (\Throwable $e) {
                app('logger')->warning('Domain nginx config lookup failed (using defaults): ' . $e->getMessage());
            }
            return "server {\n    listen 80;\n    listen [::]:80;\n{$extra}    server_name {$serverName} www.{$serverName};\n    return 301 {$target}\$request_uri;\n}\n";
        }

        // Merge the existing website row when present: generation must see
        // the full row (php_version for the socket, id for per-dir PHP
        // locations, ipv4_address for dedicated listens). Fresh label and
        // docroot win over stale row values.
        $website = ['label' => $domain['name'], 'document_root' => $documentRoot];
        try {
            $row = app('db')->selectOne(
                'SELECT * FROM `websites`
                 WHERE `account_id` = ? AND `domain_id` = ? AND `status` = ?
                 ORDER BY `updated_at` DESC, `id` DESC LIMIT 1',
                [(int) $account['id'], (int) $domain['id'], 'active']
            );
            if ($row !== null) {
                $website = array_merge($row, $website);
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Domain website row lookup failed (using defaults): ' . $e->getMessage());
        }
        return app(NginxConfigGenerator::class)->generate($website, ['name' => $serverName], $account);
    }

    /**
     * Provision everything for a newly-added domain:
     * document root + DNS + website/vhost. Returns the website id (or null).
     */
    /**
     * Remove a domain's artifacts (best-effort; each step logged, never fatal).
     * Files on disk are left intact — deleting a domain must never destroy
     * customer data. The DB row is deleted by the caller after this returns.
     *
     * @return array<int,array{step:string,ok:bool,detail:string}>
     */
    public function deprovision(array $domain): array
    {
        $out = [];
        $step = function (string $label, callable $fn) use (&$out): void {
            try {
                $fn();
                $out[] = ['step' => $label, 'ok' => true, 'detail' => ''];
            } catch (\Throwable $e) {
                $out[] = ['step' => $label, 'ok' => false, 'detail' => $e->getMessage()];
                app('logger')->warning("Domain deprovision '{$label}' failed: " . $e->getMessage());
            }
        };

        $name = (string) $domain['name'];
        $accountId = (int) $domain['account_id'];
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        $username = (string) ($account['username'] ?? '');

        // Front-door + per-account worker vhosts and website rows.
        foreach (app('db')->select('SELECT * FROM `websites` WHERE `domain_id` = ?', [(int) $domain['id']]) as $w) {
            $label = (string) ($w['label'] ?? $name);
            $step("vhost {$label}", fn () => app(\Modules\Websites\Services\VhostWriter::class)->delete($label));
            $step("waf-conf {$label}", function () use ($label): void {
                try {
                    app('provisioning')->execute('waf.rules.delete', ['domain' => $label]);
                } catch (\Throwable $e) {
                    // Already absent / no WAF: the vhost is gone by now, so a
                    // leftover file is harmless — just tidy it.
                    @unlink('/etc/modsecurity/' . $label . '.conf');
                }
            });
            if ($username !== '') {
                $step("worker-vhost {$label}", fn () => app('provisioning')->execute('file.delete', [
                    'account' => $username,
                    'path' => "nginx/conf.d/{$label}.conf",
                ]));
            }
            $step("website-row {$label}", function () use ($w): void {
                $db = app('db');
                $wid = (int) $w['id'];
                // No FK cascade on these: a deleted website used to leave its
                // WAF/privacy/php-dir state behind (and a future row reusing
                // the id would inherit another site's config).
                foreach (['website_protected_dirs'] as $t) {
                    foreach ($db->select("SELECT `id` FROM `{$t}` WHERE `website_id` = ?", [$wid]) as $row) {
                        try { $db->delete('website_dir_users', '`protected_dir_id` = ?', [(int) $row['id']]); } catch (\Throwable $e) {}
                    }
                }
                foreach ([
                    'security_settings', 'website_waf_exclusions', 'website_php_dirs',
                    'website_protected_dirs', 'bandwidth_cursors',
                ] as $t) {
                    try { $db->delete($t, '`website_id` = ?', [$wid]); } catch (\Throwable $e) {}
                }
                $db->delete('websites', '`id` = ?', [$wid]);
            });
        }

        // Mail domain row (provisioned for every hosted domain) + map resync.
        $step('mail-domain', function () use ($name, $domain): void {
            if (!module_enabled('email')) {
                return;
            }
            app('db')->delete('mail_domains', '`domain` = ? AND `account_id` = ?', [$name, (int) $domain['account_id']]);
            app(\Modules\Email\Services\MailService::class)->sync();
        });

        // DNS: drop the zone (addon/main/parked) or the record in the parent
        // zone (subdomain). Skip when another domain row still owns the name.
        $step('dns', function () use ($name, $domain): void {
            if (!module_enabled('dns')) {
                return;
            }
            $other = app('db')->selectOne('SELECT `id` FROM `domains` WHERE `name` = ? AND `id` <> ?', [$name, (int) $domain['id']]);
            if ($other !== null) {
                return;
            }
            $dns = app(\Modules\Dns\Services\DnsService::class);
            if (($domain['type'] ?? '') === 'subdomain' && !empty($domain['parent_domain_id'])) {
                $parent = app('db')->selectOne('SELECT `name` FROM `domains` WHERE `id` = ?', [(int) $domain['parent_domain_id']]);
                if ($parent !== null) {
                    $dns->deleteRecord((string) $parent['name'], $name, 'A');
                    $dns->deleteRecord((string) $parent['name'], $name, 'AAAA');
                }
                return;
            }
            try {
                $dns->deleteZone($name);
            } catch (\Throwable $e) {
                // Idempotent: zone may never have existed (or was already
                // removed) — anything else is a real failure.
                if (!str_contains($e->getMessage(), 'not found')) {
                    throw $e;
                }
            }
            // Drop the panel's zone row too (the delete above only removes the
            // PowerDNS zone) — stale rows break DNS exports and zone lists.
            app('db')->delete('dns_zones', '`name` = ? AND `account_id` = ?', [$name, (int) $domain['account_id']]);
        });

        return $out;
    }

    public function provision(array $account, array $domain): ?int
    {
        $type = $domain['type'];

        // Resolve the parent domain (for subdomains) to derive the docroot and DNS.
        $parent = null;
        if ($type === 'subdomain' && !empty($domain['parent_domain_id'])) {
            $parent = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) $domain['parent_domain_id']]);
        }

        $documentRoot = self::documentRoot(
            $account,
            $type,
            $domain['name'],
            $parent ? ($parent['document_root'] ?? self::documentRoot($account, 'main', $parent['name'])) : null
        );

        // Parked domains share the primary docroot (no new folder); all others
        // get their own directory.
        if ($type !== 'parked') {
            $this->ensureDocumentRoot($account, $documentRoot);
        }

        // DNS records are best-effort: a missing zone (or a disabled Dns module)
// must never abort domain provisioning — the site row/docroot/vhost are the
// primary artifacts. Failures are logged for the admin.
        try {
            $this->provisionDns($account, $domain, $parent);
        } catch (\Throwable $e) {
            app('logger')->warning('DNS provisioning failed for ' . $domain['name'] . ': ' . $e->getMessage());
        }

        // Every hosted domain is mail-ready from minute one (cPanel
        // semantics): ensure its mail_domains row so forwarders, catch-all,
        // mailing lists, spam and sieve filters exist without requiring a
        // first mailbox. Empty maps render safely; the mailbox flow tops
        // this up idempotently. Never fatal to domain provisioning.
        try {
            $mailRow = app('db')->selectOne(
                'SELECT `id` FROM `mail_domains` WHERE `domain` = ? AND `account_id` = ?',
                [$domain['name'], (int) $account['id']]
            );
            if ($mailRow === null) {
                app('db')->insert('mail_domains', [
                    'domain' => $domain['name'],
                    'account_id' => (int) $account['id'],
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                app(\Modules\Email\Services\MailService::class)->sync();
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Mail domain ensure failed for ' . $domain['name'] . ': ' . $e->getMessage());
        }

        // Persist the computed document root back on the domain row.
        app('db')->update('domains', ['document_root' => $documentRoot], '`id` = ?', [(int) $domain['id']]);
        $domain['document_root'] = $documentRoot;

        // Parked domains: alias the primary (same content) or redirect — they
        // do not need a website row. Everything else gets a website + vhost.
        $nginx = $this->nginxConfig($account, $domain, $documentRoot, $parent);

        if ($type === 'parked') {
            try {
                $this->writeVhost($account, $domain['name'], $nginx);
            } catch (\Throwable $e) {
                app('logger')->warning('Parked domain vhost write failed: ' . $e->getMessage());
            }
            return null;
        }

        // One website row per domain: re-provisioning must reuse the existing
        // row, never stack a duplicate that would fight over one vhost file.
        $existing = app('db')->selectOne(
            'SELECT `id`, `php_version` FROM `websites`
             WHERE `account_id` = ? AND `domain_id` = ? AND `status` = ?',
            [$account['id'], $domain['id'], 'active']
        );

        if ($existing !== null) {
            $websiteId = (int) $existing['id'];
            app('db')->update('websites', [
                'label' => $domain['name'],
                'document_root' => $documentRoot,
                'nginx_config' => $nginx,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$websiteId]);
        } else {
            $websiteId = app('db')->insert('websites', [
                'account_id' => $account['id'],
                'domain_id' => $domain['id'],
                'label' => $domain['name'],
                'document_root' => $documentRoot,
                // Match the master the provisioner creates for new accounts
                // (system PHP, id "84"). The vhost fastcgi_pass must point at
                // an existing master socket from minute one.
                'php_version' => \Modules\Core\Services\PhpVersionsService::packageDefaultForAccount((int) $account['id']),
                'ssl_enabled' => 0,
                'nginx_config' => $nginx,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // WAF: give every website an explicit security_settings row following
        // the global default (on unless security.waf_default=0), and regenerate
        // so the front-door vhost carries the ModSecurity block + rules file.
        try {
            $sec = app('db')->selectOne(
                'SELECT `id` FROM `security_settings` WHERE `website_id` = ?',
                [$websiteId]
            );
            if ($sec === null) {
                $wafOn = app(\Modules\Security\Services\WafService::class)->defaultEnabled();
                app('db')->insert('security_settings', [
                    'account_id' => $account['id'],
                    'website_id' => $websiteId,
                    'hotlink_protection' => 0,
                    'waf_enabled' => $wafOn ? 1 : 0,
                    'waf_mode' => $wafOn ? 'on' : 'detection',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                app(\Modules\Security\Services\WafService::class)->regenerate($websiteId);
                app(\Modules\Websites\Services\PrivacyService::class)->regenerate($websiteId);
            }
        } catch (\Throwable $e) {
            app('logger')->warning('WAF default provisioning failed: ' . $e->getMessage());
        }

        try {
            $this->writeVhost($account, $domain['name'], $nginx);
        } catch (\Throwable $e) {
            app('logger')->warning('Nginx vhost write failed: ' . $e->getMessage());
        }

        // Automatic Let's Encrypt: queue a background issuance when the
        // domain resolves to this server (the job re-checks DNS at run
        // time and skips quietly when it does not point here yet — e.g.
        // DNS still propagating). Parked aliases share the primary vhost
        // and are excluded inside queueAutoIssue.
        try {
            app(\Modules\Ssl\Services\SslService::class)->queueAutoIssue(
                (int) $account['id'],
                (int) $domain['id'],
                (string) $domain['name']
            );
        } catch (\Throwable $e) {
            app('logger')->warning('Auto-SSL queue failed for ' . $domain['name'] . ': ' . $e->getMessage());
        }

        return (int) $websiteId;
    }

    /**
     * Persist a domain's vhost. When per-account nginx worker isolation is
     * enabled, the vhost is routed through the account's dedicated worker;
     * otherwise it is written to the shared front-door nginx.
     */
    private function writeVhost(array $account, string $name, string $nginx): void
    {
        $isolation = app(NginxIsolationService::class);

        if (!$isolation->isEnabled($account)) {
            app(VhostWriter::class)->write($name, $nginx);
            return;
        }

        $port = $isolation->portFor((int) $account['id']);

        if ($port === null) {
            app(VhostWriter::class)->write($name, $nginx);
            return;
        }

        $isolation->write($account);
        $isolation->rewriteVhosts($account, $port);
    }
}
