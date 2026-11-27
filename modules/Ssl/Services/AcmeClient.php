<?php
declare(strict_types=1);
namespace Modules\Ssl\Services;

/**
 * Minimal but correct ACME v2 (RFC 8555) client, HTTP-01 challenge.
 *
 * Flow: newAccount -> newOrder -> getChallenge -> respond to HTTP-01 ->
 * poll -> finalize -> download certificate.
 *
 * Uses a RSA account key, RS256 JWS signing, and the Let's Encrypt staging
 * directory unless overridden. The HTTP-01 challenge file is written via the
 * agent (file.write) into the account's public web root.
 */
class AcmeClient
{
    private string $directory;

    private string $accountKey = '';

    private ?string $accountUrl = null;

    private string $lastNonce = '';

    private ?array $directoryJson = null;

    public function __construct(string $directory = 'https://acme-staging-v02.api.letsencrypt.org/directory', ?string $accountKeyPem = null)
    {
        $this->directory = $directory;
        if ($accountKeyPem !== null && $accountKeyPem !== '') {
            $this->accountKey = $accountKeyPem;
        }
    }

    /**
     * Fetch + cache the ACME directory object, returning the endpoint URL map.
     */
    private function endpoints(): array
    {
        if ($this->directoryJson !== null) {
            return $this->directoryJson;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->directory,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('failed to fetch ACME directory');
        }

        $this->directoryJson = json_decode($raw, true);
        if (!is_array($this->directoryJson)) {
            throw new \RuntimeException('invalid ACME directory');
        }

        return $this->directoryJson;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    public function issue(string $domain, string $account, callable $writeChallenge): array
    {
        $nonce = $this->newNonce();
        $this->initializeAccount($nonce);

        $order = $this->newOrder($domain);

        // Resolve the HTTP-01 authorization + challenge + token.
        $challenge = $this->findHttp01($order);

        $domainToken = $challenge['token'];
        $keyAuth = $this->keyAuthorization($challenge['token']);
        $writeChallenge($domainToken, $keyAuth);

        // Acknowledge the challenge (POST {} to the challenge URL).
        $this->acknowledgeChallenge($challenge['url']);

        // Poll until the order is ready (returns the fresh order incl. finalize URL).
        $order = $this->pollOrder($order['url'], $order);

        // Finalize with a CSR.
        [$keyPem, $csrPem] = $this->makeCsr($domain);
        $finalized = $this->finalize($order, $csrPem);

        // Download the cert (PEM).
        $certPem = $this->download($finalized['certificate']);

        return [
            'key' => $keyPem,
            'cert' => $certPem,
            'chain' => '',
            'type' => 'acme',
        ];
    }

    // ------------------------------------------------------------------
    // ACME steps
    // ------------------------------------------------------------------

    private function initializeAccount(string $nonce): void
    {
        $jwk = $this->publicJwk();
        $endpoint = $this->endpoints()['newAccount'] ?? $this->directory;

        // Look up an existing account for this key first (onlyReturnExisting).
        // A fresh key answers 400 accountDoesNotExist — that is the normal
        // first-run path, not a failure: fall through to account creation.
        try {
            $lookup = $this->signedRequest(
                $endpoint,
                ['onlyReturnExisting' => true],
                $nonce,
                $jwk,
                true
            );
        } catch (AcmeException $e) {
            if ($e->type !== 'urn:ietf:params:acme:error:accountDoesNotExist') {
                throw $e;
            }
            $lookup = ['headers' => []];
        }

        $this->accountUrl = $lookup['headers']['location'] ?? null;

        if ($this->accountUrl !== null) {
            return;
        }

        // No account yet -> create one.
        $create = $this->signedRequest(
            $endpoint,
            ['termsOfServiceAgreed' => true],
            $this->newNonce(),
            $jwk,
            true
        );

        $this->accountUrl = $create['headers']['location'] ?? null;

        if ($this->accountUrl === null) {
            throw new \RuntimeException('ACME account URL not returned');
        }
    }

    private function newNonce(): string
    {
        $url = $this->endpoints()['newNonce'] ?? null;
        if ($url === null) {
            throw new \RuntimeException('ACME directory missing newNonce endpoint');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_POST => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('failed to fetch ACME nonce');
        }

        $headerStr = substr($raw, 0, $headerSize);
        foreach (explode("\r\n", $headerStr) as $line) {
            if (stripos($line, 'replay-nonce:') === 0) {
                $this->lastNonce = trim(substr($line, strlen('replay-nonce:')));
                return $this->lastNonce;
            }
        }

        throw new \RuntimeException("no replay-nonce in ACME response (http $code)");
    }

    private function newOrder(string $domain): array
    {
        $nonce = $this->newNonce();
        $resp = $this->signedRequest(
            $this->endpoints()['newOrder'] ?? $this->directory,
            ['identifiers' => [['type' => 'dns', 'value' => $domain]]],
            $nonce,
            null
        );
        $order = $resp['body'];
        // The order URL is returned in the Location header.
        $order['url'] = $resp['headers']['location'] ?? null;
        return $order;
    }

    private function findHttp01(array $order): array
    {
        foreach ($order['authorizations'] as $authUrl) {
            $auth = $this->postAsGet($authUrl, $this->newNonce(), null);
            foreach ($auth['body']['challenges'] ?? [] as $ch) {
                if ($ch['type'] === 'http-01') {
                    $ch['authorization'] = $authUrl;
                    return $ch;
                }
            }
        }
        throw new \RuntimeException('no http-01 challenge found');
    }

    private function pollOrder(string $orderUrl, array $order, int $attempts = 30): array
    {
        for ($i = 0; $i < $attempts; $i++) {
            $state = $this->postAsGet($orderUrl, $this->newNonce(), null);
            $status = $state['body']['status'] ?? '';

            if ($status === 'ready') {
                $ready = $state['body'];
                $ready['url'] = $orderUrl;
                return $ready;
            }
            if ($status === 'invalid') {
                throw new \RuntimeException('order became invalid');
            }
            sleep(2);
        }
        throw new \RuntimeException('timed out waiting for order');
    }

    private function acknowledgeChallenge(string $challengeUrl): array
    {
        // RFC 8555 §7.5.1: trigger validation by POSTing an empty JSON object.
        return $this->signedRequest($challengeUrl, new \stdClass(), $this->newNonce(), null);
    }

    private function finalize(array $order, string $csrPem): array
    {
        $csrDer = $this->pemToDer($csrPem);

        $finalize = $this->signedRequest(
            $order['finalize'],
            ['csr' => $this->b64url($csrDer)],
            $this->newNonce(),
            null
        );

        $body = $finalize['body'];

        // The certificate URL appears once the order reaches "valid".
        if (empty($body['certificate']) && !empty($order['url'])) {
            for ($i = 0; $i < 30; $i++) {
                $state = $this->postAsGet($order['url'], $this->newNonce(), null);
                $status = $state['body']['status'] ?? '';
                if ($status === 'valid' && !empty($state['body']['certificate'])) {
                    return $state['body'];
                }
                if ($status === 'invalid') {
                    throw new \RuntimeException('order became invalid during finalize');
                }
                sleep(2);
            }
            throw new \RuntimeException('timed out waiting for certificate');
        }

        return $body;
    }

    private function download(string $certUrl): string
    {
        $body = $this->postAsGet($certUrl, $this->newNonce(), null);
        return $body['raw'];
    }

    // ------------------------------------------------------------------
    // HTTP + JWS
    // ------------------------------------------------------------------

    private function httpRequest(string $url, string $body, array $extraHeaders = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/jose+json'], $extraHeaders),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('acme http error: ' . curl_error($ch));
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerStr = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        if (isset($headers['replay-nonce'])) {
            $this->lastNonce = $headers['replay-nonce'];
        }

        $decoded = json_decode($body, true) ?: [];

        // Surface ACME errors (429 rate-limit, badNonce, failed validation, etc.)
        // as a typed exception so callers can back off instead of blind retrying.
        if ($httpCode >= 400) {
            $type = is_array($decoded) ? ($decoded['type'] ?? null) : null;
            $detail = is_array($decoded) ? ($decoded['detail'] ?? null) : null;

            $retryAfter = null;
            if (isset($headers['retry-after'])) {
                $retryAfter = (int) $headers['retry-after'];
            }

            $acmeType = is_string($type) ? $type : null;
            $message = is_string($detail) && $detail !== ''
                ? $detail
                : "ACME request failed (http {$httpCode})" . ($acmeType !== null ? " [{$acmeType}]" : '');

            throw new AcmeException($message, $httpCode, $acmeType, $retryAfter);
        }

        return ['headers' => $headers, 'raw' => $body, 'body' => $decoded];
    }

    private function signedRequest(string $url, $payload, string $nonce, ?array $jwk, bool $wantLocation = false): array
    {
        $protected = [
            'alg' => 'RS256',
            'nonce' => $nonce,
            'url' => $url,
        ];
        if ($this->accountUrl !== null && $jwk === null) {
            $protected['kid'] = $this->accountUrl;
        } else {
            $protected['jwk'] = $jwk ?? $this->publicJwk();
        }

        $jws = $this->sign($protected, $payload);

        return $this->httpRequest($url, $jws);
    }

    private function postAsGet(string $url, string $nonce, ?array $jwk): array
    {
        $protected = [
            'alg' => 'RS256',
            'nonce' => $nonce,
            'url' => $url,
        ];
        if ($this->accountUrl !== null) {
            $protected['kid'] = $this->accountUrl;
        } else {
            $protected['jwk'] = $jwk ?? $this->publicJwk();
        }

        $jws = $this->sign($protected, '');
        return $this->httpRequest($url, $jws);
    }

    private function sign(array $protected, $payload): string
    {
        $p = $this->b64url(json_encode($protected));
        $pl = $this->b64url(is_string($payload) ? $payload : json_encode($payload));

        openssl_sign($p . '.' . $pl, $signature, $this->accountKey, OPENSSL_ALGO_SHA256);

        return json_encode([
            'protected' => $p,
            'payload' => $pl,
            'signature' => $this->b64url($signature),
        ]);
    }

    // ------------------------------------------------------------------
    // Key / JWK / CSR
    // ------------------------------------------------------------------

    private function ensureAccountKey(): void
    {
        if ($this->accountKey !== '') {
            return;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new \RuntimeException('failed to generate ACME account key');
        }

        openssl_pkey_export($key, $out);
        $this->accountKey = $out;
    }

    private function publicJwk(): array
    {
        $this->ensureAccountKey();
        $privKey = openssl_pkey_get_private($this->accountKey);

        if ($privKey === false) {
            throw new \RuntimeException('failed to load ACME account key');
        }

        $details = openssl_pkey_get_details($privKey);

        // In PHP 8 the RSA exponent is a big-endian binary string; accept both
        // forms (older PHP returned an int).
        $e = $details['rsa']['e'];
        if (is_int($e)) {
            $e = $this->intToBytes($e);
        }

        return [
            'e' => $this->b64url($e),
            'kty' => 'RSA',
            'n' => $this->b64url($details['rsa']['n']),
        ];
    }

    private function keyAuthorization(string $token): string
    {
        $jwk = $this->publicJwk();
        $thumbprint = $this->b64url(
            hash('sha256', json_encode(['e' => $jwk['e'], 'kty' => 'RSA', 'n' => $jwk['n']]), true)
        );
        return $token . '.' . $thumbprint;
    }

    private function makeCsr(string $domain): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $dn = ['commonName' => $domain];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrPem);
        openssl_pkey_export($key, $keyOut);

        return [$keyOut, $csrPem];
    }

    /**
     * Convert a PEM certificate/CSR to DER (strip headers + base64-decode body).
     */
    private function pemToDer(string $pem): string
    {
        $pem = trim($pem);
        $pem = preg_replace('/-----(?:BEGIN|END)[^-]+-----/', '', $pem);
        $pem = preg_replace('/\s+/', '', $pem);
        $der = base64_decode($pem);
        if ($der === false) {
            throw new \RuntimeException('failed to decode PEM');
        }
        return $der;
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function intToBytes(int $int): string
    {
        $bytes = '';
        while ($int > 0) {
            $bytes = chr($int & 0xFF) . $bytes;
            $int >>= 8;
        }
        return $bytes === '' ? "\0" : $bytes;
    }
}