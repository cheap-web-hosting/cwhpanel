<?php
declare(strict_types=1);
namespace Modules\Webmail\Services;

/**
 * Single sign-on tokens for the bundled Roundcube ("advanced webmail").
 *
 * The panel sets the token as an HttpOnly cookie scoped to /roundcube; the
 * cwhpanel_sso Roundcube plugin (deploy/roundcube/plugins/) validates it and
 * logs the mailbox in via Dovecot master-user auth — the same identity path as
 * the panel's built-in webmail, so no mailbox password is needed.
 *
 * Token: <b64url(email)>.<expiry>.<nonce>.<hmac-hex>,
 *   hmac = HMAC-SHA256(email . '|' . expiry . '|' . nonce, ROUNDCUBE_SSO_KEY).
 * 60-second TTL: long enough for the redirect, too short to be useful if
 * leaked into logs or history.
 *
 * Replay prevention (audit #11): the nonce is stored as a file in the
 * Roundcube temp dir before the redirect and the plugin *unlinks* it
 * atomically (exactly one caller can unlink a file) — a captured token works
 * once, never twice, within its TTL. Cookie expiry is not replay prevention.
 *
 * The nonce file is only written when the installed plugin advertises nonce
 * support (its source carries CWH_SSO_NONCE). An older plugin keeps receiving
 * the legacy 3-part token so a panel-only update never breaks webmail; the
 * plugin is upgraded by re-running scripts/install-roundcube.sh.
 */
class RoundcubeSso
{
    public const TTL = 60;

    /** Marker the plugin source must contain to enable nonce tokens. */
    public const PLUGIN_MARKER = 'CWH_SSO_NONCE';

    public const PLUGIN_PATH = '/usr/share/roundcubemail/plugins/cwhpanel_sso/cwhpanel_sso.php';

    /** Roundcube's configured temp_dir (config.inc.php writes /var/lib/roundcube/temp/). */
    public const TEMP_DIR = '/var/lib/roundcube/temp';

    /** Nonce files older than this are pruned on the next issuance. */
    private const NONCE_MAX_AGE = 300;

    private static ?bool $nonceSupported = null;

    public static function key(): string
    {
        return (string) env('ROUNDCUBE_SSO_KEY', '');
    }

    /** @throws \RuntimeException when the SSO key is not configured */
    public static function issue(string $email): string
    {
        $key = self::key();
        if ($key === '') {
            throw new \RuntimeException('Roundcube single sign-on is not configured.');
        }
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Invalid mailbox address.');
        }
        $exp = (string) (time() + self::TTL);
        $b64 = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');

        if (!self::nonceSupported()) {
            $sig = hash_hmac('sha256', $email . '|' . $exp, $key);

            return $b64 . '.' . $exp . '.' . $sig;
        }

        $nonce = bin2hex(random_bytes(16));
        self::storeNonce($nonce);
        $sig = hash_hmac('sha256', $email . '|' . $exp . '|' . $nonce, $key);

        return $b64 . '.' . $exp . '.' . $nonce . '.' . $sig;
    }

    /** Returns the mailbox address, or null when the token is bad/expired. */
    public static function validate(string $token): ?string
    {
        $key = self::key();
        if ($key === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) === 4) {
            [$b64, $exp, $nonce, $sig] = $parts;
            if (!ctype_xdigit($nonce) || strlen($nonce) !== 32) {
                return null;
            }
        } elseif (count($parts) === 3) {
            [$b64, $exp, $sig] = $parts;
            $nonce = null;
        } else {
            return null;
        }
        if (!ctype_digit($exp) || (int) $exp < time()) {
            return null;
        }
        $email = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($email === false) {
            return null;
        }
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $payload = $nonce === null ? $email . '|' . $exp : $email . '|' . $exp . '|' . $nonce;
        $want = hash_hmac('sha256', $payload, $key);
        if (!hash_equals($want, strtolower($sig))) {
            return null;
        }

        return $email;
    }

    /**
     * Whether the installed Roundcube plugin consumes nonces.
     *
     * An unreadable/absent plugin (Roundcube not installed) means no nonce
     * tokens: the redirect would 404 anyway, and legacy behaviour is safest.
     */
    public static function nonceSupported(): bool
    {
        if (self::$nonceSupported !== null) {
            return self::$nonceSupported;
        }
        $path = (string) env('ROUNDCUBE_SSO_PLUGIN', self::PLUGIN_PATH);
        $src = is_readable($path) ? (string) @file_get_contents($path) : '';

        return self::$nonceSupported = str_contains($src, self::PLUGIN_MARKER);
    }

    /** Test hook: forget the cached plugin probe after switching the override. */
    public static function forgetPluginProbe(): void
    {
        self::$nonceSupported = null;
    }

    public static function nonceDir(): string
    {
        $tmp = (string) env('ROUNDCUBE_SSO_TEMP', self::TEMP_DIR);

        return rtrim($tmp, '/') . '/cwhpanel-sso';
    }

    /** @throws \RuntimeException when the nonce cannot be persisted (fail closed) */
    private static function storeNonce(string $nonce): void
    {
        $dir = self::nonceDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Roundcube single sign-on is unavailable (nonce directory).');
        }
        // A root CLI (tests, maintenance) creates the spool dir as root; the
        // Roundcube PHP process must still be able to unlink inside it. Hand
        // ownership to the temp dir's owner, mirroring the web process.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $parent = @stat(dirname($dir));
            if ($parent !== false) {
                @chown($dir, (int) $parent['uid']);
                @chgrp($dir, (int) $parent['gid']);
            }
        }
        self::pruneNonces($dir);
        $path = $dir . '/' . $nonce;
        if (@file_put_contents($path, (string) time(), LOCK_EX) === false) {
            throw new \RuntimeException('Roundcube single sign-on is unavailable (nonce write).');
        }
        @chmod($path, 0600);
    }

    private static function pruneNonces(string $dir): void
    {
        $cutoff = time() - self::NONCE_MAX_AGE;
        foreach ((array) @glob($dir . '/*') as $file) {
            $name = basename((string) $file);
            if (preg_match('/^[0-9a-f]{32}$/', $name) !== 1) {
                continue;
            }
            $mtime = @filemtime((string) $file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink((string) $file);
            }
        }
    }
}
