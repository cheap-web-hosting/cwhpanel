<?php
declare(strict_types=1);
namespace Modules\Authentication\Services;

/**
 * TOTP (RFC 6238) implementation, compatible with Google Authenticator /
 * Authy style 6-digit codes, 30-second step, HMAC-SHA1, base32 secrets.
 *
 * No external dependencies.
 */
class Totp
{
    private const ALGO = 'sha1';
    private const DIGITS = 6;
    private const PERIOD = 30;

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        return $this->verifyStep($secret, $code, $window) !== null;
    }

    /**
     * Like verify(), but returns the accepted time-step so callers can persist
     * it and refuse replays of the same code.
     */
    public function verifyStep(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code);

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = (int) floor(time() / self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals((string) $this->codeAt($secret, $now + $offset), $code)) {
                return $now + $offset;
            }
        }

        return null;
    }

    private function codeAt(string $secret, int $counter): int
    {
        $key = $this->base32Decode($secret);
        // RFC 4226/6238 use an 8-byte big-endian counter; the time-step value
        // occupies the low 4 bytes and the upper 4 are zero.
        $counterBytes = pack('N2', 0, $counter);

        $hash = hash_hmac(self::ALGO, $counterBytes, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return $binary % (10 ** self::DIGITS);
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $issuer = rawurlencode(config('app.name', 'Control Panel'));
        $label = rawurlencode($email);
        return "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0');
            $out .= $alphabet[bindec($chunk)];
        }

        return $out;
    }

    public function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32));

        $binary = '';
        foreach (str_split($base32) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) {
                continue;
            }
            $binary .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $out .= chr(bindec($chunk));
        }

        return $out;
    }
}