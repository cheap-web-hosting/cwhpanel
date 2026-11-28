<?php
declare(strict_types=1);
namespace Modules\Api\Services;

/**
 * WebhookPolicy — SSRF guard for outbound webhook URLs, enforced both at
 * store time (AdminApiController) and at send time (JobRunner::post, which
 * re-checks to narrow DNS-rebinding windows).
 *
 * Rules: http/https only, resolvable host, no loopback/private/link-local
 * targets (literal or resolved), no localhost-style names. Fail-closed:
 * unresolvable hosts are refused (send-time misses ride the job backoff).
 */
class WebhookPolicy
{
    /** @return ?string error, or null when the URL may be used */
    public static function checkUrl(string $url): ?string
    {
        $url = trim($url);
        if (strlen($url) > 255 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Invalid URL.';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'Webhook URL must use http:// or https://.';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        // parse_url keeps brackets on IPv6 literals ([::1]); strip them so
        // the literal-IP check below sees the real address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            return 'Webhook URL has no host.';
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.invalid')) {
            return 'Webhook host is not allowed.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                ? 'Webhook host must be a public address.'
                : null;
        }
        $ips = self::resolve($host);
        if ($ips === []) {
            return 'Webhook host does not resolve.';
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Webhook host resolves to a private address.';
            }
        }
        return null;
    }

    /**
     * Curl options that pin a checked webhook URL's host to a public address
     * resolved NOW (closes the check-to-connect DNS-rebinding window) and
     * refuse redirects (a public host must not bounce the request inward).
     * Throws when a hostname no longer resolves to any public address.
     *
     * @return array<int,mixed>
     */
    public static function curlPinnedOptions(string $url): array
    {
        $opts = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0];
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $bare = trim($host, '[]');
        if ($bare === '') {
            throw new \RuntimeException('webhook URL has no host');
        }
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return $opts;
        }
        $ip = null;
        foreach (self::resolve($bare) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                $ip = $candidate;
                break;
            }
        }
        if ($ip === null) {
            throw new \RuntimeException('webhook host no longer resolves to a public address');
        }
        $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ip];

        return $opts;
    }

    /** @return array<int,string> resolved A + AAAA addresses (empty = unresolvable) */
    private static function resolve(string $host): array
    {
        $out = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) $out[] = $ip;
        }
        try {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ipv6'])) $out[] = (string) $r['ipv6'];
                }
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Webhook policy DNS (AAAA) lookup failed for ' . $host . ': ' . $e->getMessage());
        }
        if ($out === [] && $v4 === false) {
            app('logger')->warning('Webhook policy could not resolve ' . $host . ' (A and AAAA both failed)');
        }
        return array_values(array_unique($out));
    }
}
