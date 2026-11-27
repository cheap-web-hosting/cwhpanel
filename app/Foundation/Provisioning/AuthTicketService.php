<?php

declare(strict_types=1);

namespace App\Foundation\Provisioning;

/**
 * AuthTicketService — mints signed request tickets for the privileged agent.
 *
 * Trust model (see docs/AGENT_TRUST_MODEL.md): the agent authenticates who is
 * calling (SO_PEERCRED) and now also WHAT they may do. Every provisioning
 * request carries a short-lived, HMAC-SHA256-signed ticket binding:
 *
 *   - role (admin | customer)  → separate keys; customer code paths hold
 *     only the customer key, admin code paths only the admin key,
 *   - sub (customer account)    → agent enforces tenant scope,
 *   - op + argv hash            → ticket cannot be replayed with different
 *     operation or arguments,
 *   - exp (30–60s) + nonce     → bounded replay window.
 *
 * Wire format: `<b64url(payload)>.<b64url(hmac-sha256(key, payload))>` where
 * `payload` is the canonical claims JSON (field order below). The agent
 * verifies the signature over the exact payload bytes, so no canonicalization
 * of the claims object is required across languages.
 */
final class AuthTicketService
{
    public const TTL = 45;

    public function __construct(
        private readonly ?string $adminKey,
        private readonly ?string $customerKey,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            self::keyFromValue(config('provisioning.admin_key', '')),
            self::keyFromValue(config('provisioning.customer_key', '')),
        );
    }

    private static function keyFromValue(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }

    public function adminKey(): ?string
    {
        return $this->adminKey;
    }

    public function customerKey(): ?string
    {
        return $this->customerKey;
    }

    /**
     * Mint a ticket. Returns null when the role's key is not configured (the
     * request then travels ticketless; in agent enforce mode it is refused).
     *
     * @param array $args arguments the request will carry (hashed)
     * @param string|null $sub customer account username (customer role only)
     * @param string|null $acct primary tenant argument value, if any
     */
    public function mint(string $role, string $operation, array $args, ?string $sub = null, ?string $acct = null, ?int $ttl = null): ?string
    {
        $key = $role === 'admin' ? $this->adminKey : $this->customerKey;
        if ($key === null) {
            return null;
        }

        $exp = time() + ($ttl ?? self::TTL);

        $claims = [
            'v' => 1,
            'iss' => 'panel',
            'role' => $role,
            'sub' => $role === 'customer' ? (string) $sub : '',
            'op' => $operation,
            'argv' => self::canonicalHash($args),
            'acct' => (string) ($acct ?? ''),
            'exp' => $exp,
            'n' => bin2hex(random_bytes(16)),
        ];

        $payload = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return null;
        }

        $b64 = self::b64url($payload);
        // Signature covers the raw payload bytes (the agent decodes the b64
        // part and verifies over those exact bytes).
        $sig = self::b64url(hex2bin(hash_hmac('sha256', $payload, $key)));

        return $b64 . '.' . $sig;
    }

    /**
     * Canonical hash of an arguments map, matching the agent's computation:
     * recursive key sort (associative arrays only) + json_encode with
     * unescaped slashes/unicode. The agent hashes the parsed request args
     * with serde_json's sorted-map serialization, which produces identical
     * bytes for the same data.
     */
    public static function canonicalHash(array $args): string
    {
        return hash('sha256', self::canonicalJson($args));
    }

    private static function canonicalJson(array $args): string
    {
        self::sortRecursive($args);
        // Top level is always a MAP (JSON object): `(object)` yields `{}`
        // for empty args, matching the agent's serde map serialization.
        $json = json_encode((object) $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '{}' : $json;
    }

    private static function sortRecursive(array &$args): void
    {
        if (!array_is_list($args)) {
            ksort($args);
        }
        foreach ($args as &$value) {
            if (is_array($value)) {
                self::sortRecursive($value);
            }
        }
        unset($value);
    }

    private static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
