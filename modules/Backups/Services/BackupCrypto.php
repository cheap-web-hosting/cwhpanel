<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

/**
 * Backup content encryption (libsodium secretstream, XChaCha20-Poly1305).
 *
 * Archives stay at their normal paths/names — encrypted files are detected
 * by magic bytes, so listings, retention, S3 remotes and restores all work
 * unchanged, and legacy plaintext archives keep restoring. Key derives from
 * DB_PASSWORD via HKDF (same discipline as RemoteSecret: rotating
 * DB_PASSWORD orphans encrypted archives, re-enter... there is nothing to
 * re-enter — keep a database backup of the key material offline).
 *
 * Streaming: 1 MiB chunks, constant memory regardless of archive size.
 * Format: MAGIC(8) + secretstream header(24) + push() frames.
 */
class BackupCrypto
{
    public const MAGIC = 'CWHBENC1';
    private const CHUNK = 1048576;

    public static function key(): string
    {
        $dbPass = (string) env('DB_PASSWORD', '');
        if ($dbPass === '') {
            throw new \RuntimeException('DB_PASSWORD is not configured.');
        }
        return hash_hkdf('sha256', $dbPass, 32, 'cwhpanel-backup-content-v1');
    }

    public static function isEncrypted(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, 8);
        fclose($fh);
        return $head === self::MAGIC;
    }

    /** @throws \RuntimeException */
    public static function sealFile(string $inPath, string $outPath): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key());
        $in = @fopen($inPath, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Cannot read ' . $inPath);
        }
        $out = @fopen($outPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Cannot write ' . $outPath);
        }
        try {
            fwrite($out, self::MAGIC . $header);
            // Read one chunk ahead: `feof()` is still false right after a read
            // that ended exactly at EOF, which used to leave the final chunk
            // untagged (and therefore unverifiable) for any file whose length
            // is a multiple of CHUNK.
            $pending = fread($in, self::CHUNK);
            if ($pending === false) {
                throw new \RuntimeException('Read failed.');
            }
            while (true) {
                $next = fread($in, self::CHUNK);
                if ($next === false) {
                    throw new \RuntimeException('Read failed.');
                }
                $last = $next === '';
                $frame = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state, $pending, '', $last
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                );
                if (fwrite($out, $frame) === false) {
                    throw new \RuntimeException('Write failed.');
                }
                if ($last) {
                    break;
                }
                $pending = $next;
            }
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($state);
        }
    }

    /** @throws \RuntimeException (wrong key and tampering both fail here) */
    public static function openFile(string $inPath, string $outPath): void
    {
        $in = @fopen($inPath, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Cannot read ' . $inPath);
        }
        try {
            $magic = fread($in, 8);
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($magic !== self::MAGIC || strlen((string) $header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new \RuntimeException('Not a sealed backup file.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, self::key());
            if ($state === false) {
                throw new \RuntimeException('Cannot init decryption.');
            }
            $out = @fopen($outPath, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Cannot write ' . $outPath);
            }
            try {
                // Each push frame adds ABYTES overhead to a CHUNK plaintext read.
                $frameLen = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
                $sawFinal = false;
                while (!feof($in)) {
                    $frame = fread($in, $frameLen);
                    if ($frame === false || ($frame === '' && !feof($in))) {
                        throw new \RuntimeException('Read failed.');
                    }
                    if ($frame === '') {
                        break;
                    }
                    $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame);
                    if ($res === false) {
                        throw new \RuntimeException('Decryption failed (wrong key or tampered data).');
                    }
                    [$plain, $tag] = $res;
                    if (fwrite($out, $plain) === false) {
                        throw new \RuntimeException('Write failed.');
                    }
                    if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        $sawFinal = true;
                        break;
                    }
                }
                if (!$sawFinal) {
                    // A truncated stream authenticates frame by frame but has
                    // no final tag: refuse it rather than restoring an
                    // incomplete backup.
                    throw new \RuntimeException('Truncated backup: missing final authentication tag.');
                }
            } catch (\Throwable $e) {
                fclose($out);
                @unlink($outPath);
                throw $e;
            } finally {
                sodium_memzero($state);
            }
        } finally {
            fclose($in);
        }
    }
}
