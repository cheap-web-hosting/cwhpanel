<?php
declare(strict_types=1);
namespace Modules\Webmail\Services;

/**
 * Single sign-on tokens for the bundled Roundcube ("advanced webmail").
 *
 * The panel redirects an authenticated customer to /roundcube/?_sso=<token>;
 * the cwhpanel_sso Roundcube plugin (deploy/roundcube/plugins/) validates the
 * token and logs the mailbox in via Dovecot master-user auth — the same
 * identity path as the built-in webmail, so no mailbox password is needed.
 *
 * Token: <b64url(email)>.<expiry>.<hmac-hex>,
 *   hmac = HMAC-SHA256(email . '|' . expiry, ROUNDCUBE_SSO_KEY).
 * 60-second TTL: long enough for the redirect, too short to be useful if
 * leaked into logs or history.
 */
class RoundcubeSso
{
    public const TTL = 60;

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
        $sig = hash_hmac('sha256', $email . '|' . $exp, $key);
        return rtrim(strtr(base64_encode($email), '+/', '-_'), '=') . '.' . $exp . '.' . $sig;
    }

    /** Returns the mailbox address, or null when the token is bad/expired. */
    public static function validate(string $token): ?string
    {
        $key = self::key();
        if ($key === '') {
            return null;
        }
        $parts = explode('.', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$b64, $exp, $sig] = $parts;
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
        $want = hash_hmac('sha256', $email . '|' . $exp, $key);
        if (!hash_equals($want, strtolower($sig))) {
            return null;
        }
        return $email;
    }
}
