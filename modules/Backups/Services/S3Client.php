<?php
declare(strict_types=1);
namespace Modules\Backups\Services;

/**
 * Minimal S3-compatible client (Signature Version 4), dependency-free.
 *
 * Speaks PUT / GET / HEAD / DELETE objects and ListObjectsV2 — exactly what
 * backup offsite needs — against AWS, Backblaze B2, Wasabi, MinIO, Hetzner
 * Object Storage, etc. Path-style addressing is forced for IP/custom
 * endpoints and honored on request otherwise (virtual-hosted default).
 *
 * Large uploads stream from disk (CURLFile-free manual PUT with a file
 * handle) and sign with UNSIGNED-PAYLOAD so multi-GB archives never sit in
 * RAM and are never read twice. Retries transient failures (5xx, 429,
 * curl errors) with backoff. Plain http is refused except for loopback
 * endpoints (the hermetic e2e stub).
 */
class S3Client
{
    private string $endpoint;
    private string $bucket;
    private string $region;
    private string $key;
    private string $secret;
    private bool $pathStyle;
    private int $timeout;

    public function __construct(array $cfg)
    {
        $endpoint = rtrim(trim((string) ($cfg['endpoint'] ?? '')), '/');
        if (!preg_match('#^https?://[^/\s]+$#', $endpoint)) {
            throw new \RuntimeException('Invalid S3 endpoint.');
        }
        $bucket = trim((string) ($cfg['bucket'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new \RuntimeException('Invalid S3 bucket name.');
        }
        $this->endpoint = $endpoint;
        $this->bucket = $bucket;
        $this->region = trim((string) ($cfg['region'] ?? '')) !== '' ? trim((string) $cfg['region']) : 'us-east-1';
        $this->key = (string) ($cfg['key'] ?? '');
        $this->secret = (string) ($cfg['secret'] ?? '');
        if ($this->key === '' || $this->secret === '') {
            throw new \RuntimeException('S3 credentials missing.');
        }
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $this->pathStyle = !empty($cfg['path_style'])
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with(strtolower($host), '.localhost')
            || $host === 'localhost';
        $this->timeout = max(10, (int) ($cfg['timeout'] ?? 120));

        $scheme = (string) parse_url($endpoint, PHP_URL_SCHEME);
        $loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
            || str_starts_with($host, '127.') || str_ends_with(strtolower($host), '.localhost');
        if ($scheme !== 'https' && !$loopback) {
            throw new \RuntimeException('S3 endpoint must be https (http allowed for loopback only).');
        }
    }

    public function objectUrl(string $object): string
    {
        $object = ltrim($object, '/');
        $enc = implode('/', array_map('rawurlencode', explode('/', $object)));
        return $this->pathStyle
            ? $this->endpoint . '/' . $this->bucket . '/' . $enc
            : preg_replace('#^(https?://)#', '$1' . $this->bucket . '.', $this->endpoint) . '/' . $enc;
    }

    /** Upload a local file. Returns bytes sent. */
    public function put(string $localPath, string $object, string $contentType = 'application/octet-stream'): int
    {
        if (!is_file($localPath)) {
            throw new \RuntimeException('Local file missing: ' . basename($localPath));
        }
        $size = filesize($localPath);
        if ($size === false) {
            throw new \RuntimeException('Cannot stat local file.');
        }
        $fp = fopen($localPath, 'rb');
        if ($fp === false) {
            throw new \RuntimeException('Cannot read local file.');
        }
        try {
            [$code, $body] = $this->request('PUT', $object, [], [
                'Content-Type' => $contentType,
                'Content-Length' => (string) $size,
            ], $fp, $size);
        } finally {
            fclose($fp);
        }
        if ($code !== 200) {
            throw new \RuntimeException('S3 PUT failed (http ' . $code . '): ' . mb_substr(trim($body), 0, 200));
        }
        return $size;
    }

    /** Download an object to a local path. Returns bytes written. */
    public function get(string $object, string $localPath): int
    {
        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Cannot write local file.');
        }
        try {
            [$code, ] = $this->request('GET', $object, [], [], null, 0, $fp);
        } finally {
            fclose($fp);
        }
        if ($code !== 200) {
            @unlink($localPath);
            throw new \RuntimeException('S3 GET failed (http ' . $code . ').');
        }
        $size = filesize($localPath);
        return $size === false ? 0 : $size;
    }

    public function exists(string $object): bool
    {
        [$code, ] = $this->request('HEAD', $object);
        if ($code === 200) {
            return true;
        }
        if ($code === 404) {
            return false;
        }
        throw new \RuntimeException("S3 HEAD failed (http {$code}).");
    }

    public function delete(string $object): void
    {
        [$code, ] = $this->request('DELETE', $object);
        if ($code !== 200 && $code !== 204 && $code !== 404) {
            throw new \RuntimeException("S3 DELETE failed (http {$code}).");
        }
    }

    /**
     * List object keys under a prefix (ListObjectsV2, follows continuation).
     * @return array<int, string>
     */
    public function list(string $prefix, int $limit = 1000): array
    {
        $keys = [];
        $token = null;
        do {
            $params = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000'];
            if ($token !== null) {
                $params['continuation-token'] = $token;
            }
            [$code, $body] = $this->request('GET', '', $params);
            if ($code !== 200) {
                throw new \RuntimeException("S3 LIST failed (http {$code}).");
            }
            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                throw new \RuntimeException('S3 LIST returned invalid XML.');
            }
            foreach ($xml->Contents ?? [] as $c) {
                $keys[] = (string) $c->Key;
                if (count($keys) >= $limit) {
                    return $keys;
                }
            }
            $truncated = strtolower(trim((string) ($xml->IsTruncated ?? 'false')));
            $token = $truncated === 'true' ? (string) ($xml->NextContinuationToken ?? '') : null;
            if ($token === '') {
                $token = null;
            }
        } while ($token !== null);
        return $keys;
    }

    /**
     * Signed request with retry. Returns [http code, body].
     * @throws \RuntimeException
     */
    /**
     * Fail-closed request-time host check: the host we are about to connect
     * to must be public (or an explicit loopback literal under http — the
     * hermetic test stub). Mirrors WebhookPolicy::checkUrl; duplicated here
     * so the S3 client never depends on Api-module internals.
     */
    private function assertSafeRequestHost(string $host, string $scheme): ?string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            return 'S3 URL has no host.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $loopback = str_starts_with($host, '127.') || $host === '::1';
            if ($scheme === 'http' && $loopback) {
                return null;
            }
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return null;
            }
            return 'S3 endpoint is not a public address at request time.';
        }
        $ips = [];
        foreach ((array) @gethostbynamel($host) as $ip) {
            $ips[] = (string) $ip;
        }
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = (string) $r['ipv6'];
                }
            }
        }
        if ($ips === []) {
            return 'S3 endpoint does not resolve.';
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'S3 endpoint resolves to a private address.';
            }
        }
        return null;
    }

    private function request(string $method, string $object, array $params = [], array $headers = [], $bodyFp = null, int $bodyLen = 0, $outFp = null): array
    {
        $attempts = 3;
        $lastErr = '';
        for ($try = 1; $try <= $attempts; $try++) {
            try {
                return $this->attempt($method, $object, $params, $headers, $bodyFp, $bodyLen, $outFp);
            } catch (\RuntimeException $e) {
                $lastErr = $e->getMessage();
                if (!preg_match('/curl error|http (429|5\d\d)/', $lastErr) || $try === $attempts) {
                    throw $e;
                }
                if (is_resource($bodyFp)) {
                    rewind($bodyFp);
                }
                sleep($try * 2);
            }
        }
        throw new \RuntimeException($lastErr);
    }

    private function attempt(string $method, string $object, array $params, array $headers, $bodyFp, int $bodyLen, $outFp): array
    {
        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $dateStamp = gmdate('Ymd', $now);

        $query = '';
        if ($params !== []) {
            ksort($params);
            $pairs = [];
            foreach ($params as $k => $v) {
                $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
            $query = implode('&', $pairs);
        }
        $url = $this->objectUrl($object) . ($query !== '' ? '?' . $query : '');

        $urlParts = parse_url($url);
        // SigV4 signs the Host header exactly as sent: hostname plus the
        // port when non-default (parse_url strips it — MinIO-style :9000
        // endpoints and test stubs would otherwise never verify).
        $host = strtolower((string) ($urlParts['host'] ?? ''));
        // Re-check the address we are about to connect to at REQUEST time:
        // the create-time check in CustomerBackupController can be bypassed
        // by DNS rebinding / a hostname that changes after save. Explicit
        // loopback literals under http stay allowed (hermetic test stub).
        if (($err = $this->assertSafeRequestHost($host, (string) ($urlParts['scheme'] ?? 'https'))) !== null) {
            throw new \RuntimeException($err);
        }
        $port = isset($urlParts['port']) ? (int) $urlParts['port'] : 0;
        $defaultPort = (($urlParts['scheme'] ?? 'https') === 'http') ? 80 : 443;
        $hostHeader = ($port > 0 && $port !== $defaultPort) ? "{$host}:{$port}" : $host;
        $signed = [
            'host' => $hostHeader,
            'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
            'x-amz-date' => $amzDate,
        ];
        $all = [];
        foreach ($headers as $k => $v) {
            $all[strtolower($k)] = trim((string) $v);
        }
        $all = array_merge($all, $signed);
        ksort($all);
        $canonicalHeaders = '';
        $signedList = [];
        foreach ($all as $k => $v) {
            $canonicalHeaders .= $k . ':' . preg_replace('/\s+/', ' ', $v) . "\n";
            $signedList[] = $k;
        }
        $signedHeaders = implode(';', $signedList);
        $canonicalRequest = implode("\n", [
            $method,
            $this->canonicalUri($urlParts),
            $query,
            $canonicalHeaders,
            $signedHeaders,
            'UNSIGNED-PAYLOAD',
        ]);
        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $toSign = implode("\n", [
            'AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest),
        ]);
        $signingKey = $this->signingKey($dateStamp);
        $signature = hash_hmac('sha256', $toSign, $signingKey);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init();
        $curlHeaders = ["Authorization: {$auth}", 'x-amz-date: ' . $amzDate, 'x-amz-content-sha256: UNSIGNED-PAYLOAD'];
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            if ($lk === 'host') {
                continue;
            }
            $curlHeaders[] = "{$k}: {$v}";
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => $outFp === null,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_NOBODY => $method === 'HEAD',
        ]);
        if ($bodyFp !== null) {
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $bodyFp);
            curl_setopt($ch, CURLOPT_INFILESIZE, $bodyLen);
        }
        if ($outFp !== null) {
            curl_setopt($ch, CURLOPT_FILE, $outFp);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('S3 curl error: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 500 || $code === 429) {
            throw new \RuntimeException("S3 transient failure (http {$code}).");
        }
        return [$code, is_string($body) ? $body : ''];
    }

    private function canonicalUri(array $parts): string
    {
        // S3 rule: the canonical URI is the request path as sent on the
        // wire (segments already encoded once by objectUrl()) — never
        // re-encode here or signatures mismatch (double-encoding).
        $path = (string) ($parts['path'] ?? '/');
        return $path !== '' ? $path : '/';
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
