<?php

declare(strict_types=1);

namespace App\Foundation\Security;

/**
 * Authenticated encryption for small secrets at rest (audit #14).
 *
 * TOTP seeds used to sit in `users.two_factor_secret` as plaintext base32, so
 * a database dump (or a SQL-injection read) handed over every second factor.
 * Values are now sealed with sodium secretbox under a key derived from the
 * application key and tagged `enc:v1:` so migrations can tell them apart.
 *
 * The app key is the root of trust: rotating APP_KEY invalidates enrolled TOTP
 * secrets and administrators must re-enroll (`enroll-admin-2fa.php`).
 */
final class SecretBox
{
    private const PREFIX = 'enc:v1:';

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a sealed value. Values without the prefix are returned unchanged
     * so a pre-migration row (or a test fixture written before the migration
     * ran) keeps working until it is re-sealed.
     */
    public static function decrypt(string $stored): string
    {
        if (!self::isEncrypted($stored)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted secret is corrupt.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Encrypted secret could not be opened (APP_KEY changed?).');
        }

        return $plain;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private static function key(): string
    {
        $appKey = (string) env('APP_KEY', '');
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is required to encrypt stored secrets.');
        }

        return hash('sha256', "cwhpanel:secretbox:v1\0" . $appKey, true);
    }
}
