<?php
declare(strict_types=1);
namespace Modules\Backups\Services;

/**
 * Reversible encryption for remote-storage secrets (S3 secret keys).
 *
 * Key derivation: HKDF-SHA256 over the panel's DB password (already
 * nginx-readable, per-install unique, never stored twice). Payloads are
 * AES-256-GCM sealed blobs: base64(nonce 12B | ciphertext | tag 16B).
 *
 * Operational note: rotating DB_PASSWORD orphans stored remotes (re-enter
 * the secret). That is the documented price of having no extra key file.
 */
class RemoteSecret
{
    public static function key(): string
    {
        $dbPass = (string) env('DB_PASSWORD', '');
        if ($dbPass === '') {
            throw new \RuntimeException('DB_PASSWORD is not configured.');
        }
        return hash_hkdf('sha256', $dbPass, 32, 'cwhpanel-backup-remote-v1');
    }

    /** @throws \RuntimeException */
    public static function seal(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new \RuntimeException('Empty secret.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false || $tag === '') {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($nonce . $cipher . $tag);
    }

    /** @throws \RuntimeException */
    public static function open(string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            throw new \RuntimeException('Malformed sealed secret.');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $cipher = substr($raw, 12, -16);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed (wrong key or tampered data).');
        }
        return $plain;
    }
}
