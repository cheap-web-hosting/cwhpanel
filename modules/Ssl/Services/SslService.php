<?php

declare(strict_types=1);

namespace Modules\Ssl\Services;

use Modules\Ssl\Services\AcmeClient;
use Modules\Ssl\Services\AcmeException;

class SslService
{
    private string $acmeDirectory;

    public function __construct()
    {
        $this->acmeDirectory = config('ssl.acme_directory', 'https://acme-staging-v02.api.letsencrypt.org/directory');
    }

    /**
     * Public accessor for the ACME account key (used by the hostname-TLS
     * provisioner, which drives AcmeClient directly with a panel-webroot
     * challenge writer instead of the per-account agent flow).
     */
    public function accountKey(): string
    {
        return $this->acmeAccountKey();
    }

    /**
     * Load (or generate + persist) the long-lived Let's Encrypt account key.
     *
     * Reusing one key keeps a single ACME account across issuances, avoiding
     * account churn and per-account rate limits.
     */
    protected function acmeAccountKey(): string
    {
        $path = base_path('storage/acme-account.key');

        if (is_file($path) && filesize($path) > 0) {
            $pem = file_get_contents($path);
            if ($pem !== false && str_contains($pem, 'PRIVATE KEY')) {
                return $pem;
            }
        }

        // Generate a fresh RSA key and persist it (root-only).
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('failed to generate ACME account key');
        }
        openssl_pkey_export($key, $pem);

        if (@file_put_contents($path, $pem, LOCK_EX) === false) {
            throw new \RuntimeException('failed to persist ACME account key');
        }
        @chmod($path, 0600);

        return $pem;
    }

    /**
     * Issue a certificate via ACME (Let's Encrypt) with HTTP-01 challenge.
     * The challenge response is written to the account's public web root.
     */
    public function issueAcme(string $domain, string $account, string $webRoot): array
    {
        $client = new AcmeClient($this->acmeDirectory, $this->acmeAccountKey());

        $artifacts = $client->issue($domain, $account, function (string $token, string $keyAuth) use ($account, $webRoot) {
            // webRoot is the account's document root (absolute). file.write is
            // relative to /home/<account>, so rebase it.
            $home = '/home/' . $account;
            if (str_starts_with($webRoot, $home . '/')) {
                $rel = substr($webRoot, strlen($home) + 1);
            } else {
                $rel = trim($webRoot, '/');
            }
            $challengeDir = trim($rel . '/.well-known/acme-challenge', '/');
            // file.write cannot create missing parents (resolve_rel rejects
            // non-existent ancestors), so mkdir the challenge dir first.
            // Idempotent: mkdir -p on an existing dir succeeds.
            app('provisioning')->execute('file.mkdir', [
                'account' => $account,
                'path' => $challengeDir,
            ]);
            $rel = $challengeDir . '/' . $token;

            $ok = app('provisioning')->execute('file.write', [
                'account' => $account,
                'path' => $rel,
                'content' => base64_encode($keyAuth),
            ]);
            if ($ok['status'] !== 'success') {
                throw new \RuntimeException('failed to write ACME challenge file');
            }
        });

        // Install the issued key/cert via the agent.
        try {
            $install = app('provisioning')->execute('ssl.acme.install', [
                'domain' => $domain,
                'key' => $artifacts['key'],
                'cert' => $artifacts['cert'],
            ]);
        } catch (AcmeException $e) {
            $this->recordFailure($domain, $e);
            throw $e;
        }

        if ($install['status'] !== 'success') {
            throw new \RuntimeException('failed to install issued certificate');
        }

        // A successful issuance clears any previous backoff for this domain.
        $this->clearRetryState($domain);

        // A fresh certificate changes what the vhost serves: regenerate so
        // the 443 sibling appears (front-door and/or isolated worker copy).
        $this->refreshWebsiteTls($domain);

        return $artifacts;
    }

    /**
     * Record a rate-limit or failure backoff for a domain on its cert row(s),
     * so the renewal sweep skips it until the backoff window elapses.
     * Let's Encrypt default backoff when no explicit Retry-After is given:
     * 429/rateLimited -> 60s; other 4xx ACME errors -> 1h.
     */
    private function recordFailure(string $domain, \Throwable $e): void
    {
        $retrySeconds = ($e instanceof AcmeException) ? $e->retryAfterSeconds : null;

        if ($retrySeconds === null) {
            if ($e instanceof AcmeException && $e->isRateLimited()) {
                $retrySeconds = 60;
            } elseif ($e instanceof AcmeException && $e->status >= 400) {
                $retrySeconds = 3600;
            }
        }

        $retryAfter = $retrySeconds !== null
            ? date('Y-m-d H:i:s', time() + $retrySeconds)
            : null;

        try {
            app('db')->update('certificates', [
                'retry_after' => $retryAfter,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`domain_name` = ? AND `type` = ?', [$domain, 'acme']);
        } catch (\Throwable $ignored) {
            // Certificates table may predate the retry columns on hosts where
            // the migration has not run yet; never let bookkeeping break issuance.
        }
    }

    /**
     * Clear the backoff state for a domain after a successful issue/renew.
     */
    private function clearRetryState(string $domain): void
    {
        try {
            app('db')->update('certificates', [
                'retry_after' => null,
                'last_error' => null,
            ], '`domain_name` = ? AND `type` = ?', [$domain, 'acme']);
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * Whether a domain is currently inside a rate-limit backoff window.
     * Returns the retry_after timestamp if blocked, or null if clear.
     */
    public function retryBlocked(string $domain): ?string
    {
        try {
            $row = app('db')->selectOne(
                'SELECT `retry_after` FROM `certificates` WHERE `domain_name` = ? AND `type` = ? ORDER BY `retry_after` DESC LIMIT 1',
                [$domain, 'acme']
            );
        } catch (\Throwable $ignored) {
            return null;
        }

        $retryAfter = $row['retry_after'] ?? null;

        if ($retryAfter === null) {
            return null;
        }

        $ts = strtotime($retryAfter);
        if ($ts === false || $ts <= time()) {
            return null;
        }

        return $retryAfter;
    }

    /**
     * Renew a single ACME certificate for a domain, resolving the account
     * username + web document root from the panel DB. Returns the new cert
     * metadata, or null on failure. Throws when the domain is inside a
     * rate-limit backoff window instead of blind re-issuing.
     */
    public function renewAcme(string $domain): ?array
    {
        $blocked = $this->retryBlocked($domain);
        if ($blocked !== null) {
            throw new \RuntimeException("renewal backed off until {$blocked} (Let's Encrypt rate limit)");
        }

        $account = app('db')->selectOne(
            'SELECT a.* FROM `accounts` a
             JOIN `domains` d ON d.`account_id` = a.`id`
             WHERE d.`name` = ? AND a.`status` = ? LIMIT 1',
            [$domain, 'active']
        );

        if ($account === null) {
            return null;
        }

        $dom = app('db')->selectOne('SELECT * FROM `domains` WHERE `name` = ? LIMIT 1', [$domain]);
        $webRoot = ($dom['document_root'] ?? '') ?: ('/home/' . $account['username'] . '/public_html');

        try {
            $this->issueAcme($domain, $account['username'], $webRoot);
        } catch (\Throwable $e) {
            $this->recordFailure($domain, $e);
            throw $e;
        }

        return $this->readCert($domain);
    }

    /**
     * Find ACME certificates due for renewal (auto_renew enabled and expiring
     * within the threshold) and renew each. Certificates inside a rate-limit
     * backoff window are skipped, not re-issued. Updates the DB row on success.
     *
     * @return array{renewed:int, failed:int, skipped:int, results:array<int,array{domain:string,status:string,error?:string,retry_after?:string}>}
     */
    public function renewDue(int $daysThreshold = 30): array
    {
        $threshold = date('Y-m-d H:i:s', time() + $daysThreshold * 86400);

        $certs = app('db')->select(
            "SELECT c.* FROM `certificates` c
             WHERE c.`type` = ? AND c.`auto_renew` = 1 AND c.`status` = ?
               AND c.`expires_at` IS NOT NULL AND c.`expires_at` <= ?
             ORDER BY c.`expires_at`",
            ['acme', 'active', $threshold]
        );

        $renewed = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];

        foreach ($certs as $c) {
            $domain = (string) $c['domain_name'];

            $blocked = null;
            try {
                $blocked = $this->retryBlocked($domain);
            } catch (\Throwable $ignored) {
            }
            if ($blocked !== null) {
                $skipped++;
                $results[] = ['domain' => $domain, 'status' => 'skipped', 'retry_after' => $blocked];
                continue;
            }

            try {
                $cert = $this->renewAcme($domain);
                if ($cert === null) {
                    throw new \RuntimeException('renewal returned no certificate');
                }

                app('db')->update('certificates', [
                    'issued_at' => $cert['valid_from'] ?? date('Y-m-d H:i:s'),
                    'expires_at' => $cert['valid_to'] ?? $c['expires_at'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [(int) $c['id']]);

                audit('ssl.auto_renewed', 'certificate', (int) $c['id'], ['domain' => $domain]);
                $renewed++;
                $results[] = ['domain' => $domain, 'status' => 'renewed'];
            } catch (\Throwable $e) {
                $this->recordFailure($domain, $e);
                $failed++;
                $results[] = ['domain' => $domain, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return ['renewed' => $renewed, 'failed' => $failed, 'skipped' => $skipped, 'results' => $results];
    }

    public function issueSelfSigned(string $domain, int $days = 90): bool
    {
        $result = app('provisioning')->execute('ssl.selfsigned.issue', [
            'domain' => $domain,
            'days' => $days,
        ]);
        $ok = $result['status'] === 'success';
        if ($ok) {
            $this->refreshWebsiteTls($domain);
        }
        return $ok;
    }

    /**
     * Install a customer-supplied certificate + private key for a domain.
     * Validates before touching the cert store: the PEM must parse, its
     * CN/SAN must cover the domain (exact or wildcard), and the key must
     * match the certificate. Returns parsed validity dates for the row.
     * @throws \RuntimeException on any validation or install failure
     */
    public function installCustom(string $domain, string $certPem, string $keyPem): array
    {
        $domain = strtolower(trim($domain));
        $certPem = trim($certPem);
        $keyPem = trim($keyPem);
        if (strlen($certPem) > 65535 || strlen($keyPem) > 65535) {
            throw new \RuntimeException('Certificate or key too large (max 64 KiB each).');
        }
        if (!str_contains($certPem, '-----BEGIN CERTIFICATE-----')) {
            throw new \RuntimeException('Certificate is not valid PEM.');
        }
        if (!str_contains($keyPem, 'PRIVATE KEY-----')) {
            throw new \RuntimeException('Private key is not valid PEM.');
        }
        $cert = @openssl_x509_read($certPem);
        if ($cert === false) {
            throw new \RuntimeException('Certificate could not be parsed.');
        }
        $parsed = @openssl_x509_parse($certPem);
        if ($parsed === false) {
            throw new \RuntimeException('Certificate details could not be read.');
        }
        $names = [];
        $cn = strtolower(trim((string) ($parsed['subject']['CN'] ?? '')));
        if ($cn !== '') {
            $names[] = $cn;
        }
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $part) {
            $part = strtolower(trim($part));
            if (str_starts_with($part, 'dns:')) {
                $names[] = substr($part, 4);
            }
        }
        $covers = false;
        foreach ($names as $name) {
            if ($name === $domain || ($name === '*.' . $domain)) {
                $covers = true;
                break;
            }
        }
        if (!$covers) {
            throw new \RuntimeException('Certificate does not cover ' . $domain . '.');
        }
        if (@openssl_x509_check_private_key($certPem, $keyPem) !== true) {
            throw new \RuntimeException('Private key does not match the certificate.');
        }
        $install = app('provisioning')->execute('ssl.acme.install', [
            'domain' => $domain,
            'key' => $keyPem,
            'cert' => $certPem,
        ]);
        if (($install['status'] ?? '') !== 'success') {
            throw new \RuntimeException('failed to install custom certificate');
        }
        $this->refreshWebsiteTls($domain);
        return [
            'valid_from' => isset($parsed['validFrom_time_t']) ? date('Y-m-d H:i:s', (int) $parsed['validFrom_time_t']) : date('Y-m-d H:i:s'),
            'valid_to' => isset($parsed['validTo_time_t']) ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t']) : date('Y-m-d H:i:s'),
        ];
    }

    public function remove(string $domain): bool
    {
        $result = app('provisioning')->execute('ssl.remove', [
            'domain' => $domain,
        ]);
        $ok = $result['status'] === 'success';
        if ($ok) {
            // Dropping the cert must also drop the 443 sibling / front-door
            // TLS block, or nginx keeps serving a stale certificate path.
            $this->refreshWebsiteTls($domain);
        }
        return $ok;
    }

    /**
     * Regenerate every website vhost for a domain after its certificate
     * changes (issue/renew/remove). Best-effort: never let a vhost push
     * failure break issuance or rollback the certificate operation.
     */
    public function refreshWebsiteTls(string $domain): void
    {
        try {
            $sites = app('db')->select(
                'SELECT w.`id` FROM `websites` w
                 JOIN `domains` d ON d.`id` = w.`domain_id`
                 WHERE d.`name` = ?',
                [$domain]
            );
            foreach ($sites as $site) {
                app(\Modules\Websites\Services\PrivacyService::class)->regenerate((int) $site['id']);
            }
        } catch (\Throwable $e) {
            app('logger')->warning('TLS vhost refresh failed for ' . $domain . ': ' . $e->getMessage());
        }
    }

    public function readCert(string $domain): ?array
    {
        $certPath = "/etc/cwhpanel/ssl/{$domain}/cert.pem";
        $keyPath = "/etc/cwhpanel/ssl/{$domain}/key.pem";

        if (!is_file($certPath)) {
            return null;
        }

        $certContent = @file_get_contents($certPath);
        $keyContent = @file_get_contents($keyPath);

        if ($certContent === false) {
            return null;
        }

        $cert = @openssl_x509_parse($certContent);

        if ($cert === false) {
            return null;
        }

        return [
            'subject' => $cert['subject']['CN'] ?? $domain,
            'valid_from' => date('Y-m-d H:i:s', $cert['validFrom_time_t']),
            'valid_to' => date('Y-m-d H:i:s', $cert['validTo_time_t']),
            'issuer' => $cert['issuer']['CN'] ?? 'Self-Signed',
            'san' => $cert['extensions']['subjectAltName'] ?? $domain,
        ];
    }

    /**
     * Does a domain resolve to THIS server (shared IPv4 and/or the
     * account's dedicated IPv6)? HTTP-01 can only succeed then — issuing
     * for a domain still pointing elsewhere burns LE rate limits and
     * always fails. Checks both A and AAAA via DNS; a match on either
     * suffices (LE follows whichever address family serves the challenge).
     *
     * @return array{points:bool, detail:string}
     */
    public function pointsHere(string $domain, array $account): array
    {
        $want4 = [trim((string) env('PANEL_PUBLIC_IP', ''))];
        $want4 = array_values(array_filter($want4, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        $want6 = [];
        $v6 = trim((string) ($account['ipv6_address'] ?? ''));
        if ($v6 !== '' && filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $want6[] = strtolower($v6);
        }
        if ($want4 === [] && $want6 === []) {
            return ['points' => false, 'detail' => 'no server IP configured'];
        }
        $a = @dns_get_record($domain, DNS_A) ?: [];
        $aaaa = @dns_get_record($domain, DNS_AAAA) ?: [];
        $got4 = [];
        foreach ($a as $r) {
            if (!empty($r['ip'])) {
                $got4[] = $r['ip'];
            }
        }
        $got6 = [];
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) {
                $got6[] = strtolower($r['ipv6']);
            }
        }
        if ($got4 === [] && $got6 === []) {
            return ['points' => false, 'detail' => 'no DNS records found'];
        }
        foreach ($got4 as $ip) {
            if (in_array($ip, $want4, true)) {
                return ['points' => true, 'detail' => "A {$ip} points here"];
            }
        }
        foreach ($got6 as $ip) {
            if (in_array($ip, $want6, true)) {
                return ['points' => true, 'detail' => "AAAA {$ip} points here"];
            }
        }
        $seen = implode(',', array_merge($got4, $got6));
        return ['points' => false, 'detail' => "resolves elsewhere ({$seen})"];
    }

    /**
     * Queue an automatic LE issuance for a freshly-provisioned domain.
     * Skips parked aliases (no website row of their own) and domains that
     * already carry an active ACME cert row. Never throws: auto-SSL must
     * never break domain provisioning. Dedupe: one pending job per domain.
     */
    public function queueAutoIssue(int $accountId, int $domainId, string $domainName): void
    {
        try {
            $dom = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [$domainId]);
            if ($dom === null || ($dom['type'] ?? '') === 'parked') {
                return;
            }
            $existing = app('db')->selectOne(
                "SELECT `id` FROM `certificates` WHERE `domain_name` = ? AND `type` = 'acme' AND `status` = 'active' LIMIT 1",
                [$domainName]
            );
            if ($existing !== null) {
                return;
            }
            $live = app('db')->selectOne(
                "SELECT `id` FROM `jobs` WHERE `type` = 'ssl.autoissue' AND `status` IN ('pending','running')
                  AND `payload` LIKE ? LIMIT 1",
                ['%"domain_id":' . $domainId . '%']
            );
            if ($live !== null) {
                return;
            }
            app('db')->insert('jobs', [
                'type' => 'ssl.autoissue',
                'status' => 'pending',
                'payload' => json_encode(['account_id' => $accountId, 'domain_id' => $domainId, 'domain' => $domainName]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 3,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('ssl.autoissue.queued', 'certificate', null, ['domain' => $domainName]);
        } catch (\Throwable $e) {
            app('logger')->warning('Auto-SSL queue failed for ' . $domainName . ': ' . $e->getMessage());
        }
    }

    /**
     * Run one automatic issuance (worker context): verify the domain still
     * exists + points here, issue via ACME, record the certificate row with
     * auto_renew on. Returns a human summary for the job result. Throws on
     * hard failure (worker retries, then marks failed); SKIPS (returns a
     * skip message, no throw) when the domain does not point here yet —
     * DNS may simply not have propagated, and retrying on the worker's
     * backoff is the correct behavior, not an error.
     */
    public function runAutoIssue(int $accountId, int $domainId, string $domainName): string
    {
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        $dom = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [$domainId]);
        if ($account === null || $dom === null) {
            // The domain was removed before the queued auto-issuance ran
            // (e.g. a scratch domain deleted right after creation). Same
            // quiet-skip class as DNS-not-propagated: not an error, nothing
            // to issue — do NOT throw (throwing burns attempts and leaves a
            // failed job for a situation that is not a failure).
            return "skipped: {$domainName} no longer exists";
        }
        $check = $this->pointsHere($domainName, $account);
        if (!$check['points']) {
            return "skipped: {$domainName} does not point here ({$check['detail']})";
        }
        $webRoot = ($dom['document_root'] ?? '') ?: ('/home/' . $account['username'] . '/public_html');
        $this->issueAcme($domainName, $account['username'], $webRoot);
        $cert = $this->readCert($domainName);
        $row = app('db')->selectOne(
            "SELECT `id` FROM `certificates` WHERE `domain_name` = ? AND `type` = 'acme' AND `status` = 'active' LIMIT 1",
            [$domainName]
        );
        $now = date('Y-m-d H:i:s');
        if ($row === null) {
            $id = app('db')->insert('certificates', [
                'account_id' => $accountId,
                'domain_id' => $domainId,
                'domain_name' => $domainName,
                'type' => 'acme',
                'cert_path' => "/etc/cwhpanel/ssl/{$domainName}/cert.pem",
                'key_path' => "/etc/cwhpanel/ssl/{$domainName}/key.pem",
                'issued_at' => $cert['valid_from'] ?? $now,
                'expires_at' => $cert['valid_to'] ?? $now,
                'auto_renew' => 1,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            audit('ssl.autoissue.issued', 'certificate', (int) $id, ['domain' => $domainName]);
        } else {
            app('db')->update('certificates', [
                'account_id' => $accountId,
                'domain_id' => $domainId,
                'issued_at' => $cert['valid_from'] ?? $now,
                'expires_at' => $cert['valid_to'] ?? $now,
                'auto_renew' => 1,
                'status' => 'active',
                'updated_at' => $now,
            ], '`id` = ?', [(int) $row['id']]);
            audit('ssl.autoissue.renewed', 'certificate', (int) $row['id'], ['domain' => $domainName]);
        }
        return "issued Let's Encrypt certificate for {$domainName}";
    }
}