<?php

declare(strict_types=1);

namespace Modules\Websites\Services\Nginx;

/**
 * @internal Extracted from NginxConfigGenerator (concern group).
 */
trait IpsTrait
{


    /**
     * Append dedicated `listen` lines for the account IPv6 / website IPv4.
     * No-op unless IP management is enabled. Addresses are strictly
     * validated (never trust DB content into nginx config); invalid values
     * are skipped, never rendered.
     */
    private function injectDedicatedIps(string $config, array $website, array $account, bool $hasCert): string
    {
        try {
            if (!app(\Modules\Network\Services\IpAllocatorService::class)->enabled()) {
                return $config;
            }
        } catch (\Throwable $e) {
            return $config;
        }
        $v6 = app(\Modules\Network\Services\IpAllocatorService::class)->effectiveIpv6($account);
        $v4 = $this->cleanIpv4((string) ($website['ipv4_address'] ?? ''));
        if ($v6 === null && $v4 === null) {
            return $config;
        }
        $extra80 = '';
        $extra443 = '';
        if ($v6 !== null) {
            $extra80 .= "    listen [{$v6}]:80;\n";
            $extra443 .= "    listen [{$v6}]:443 ssl;\n";
        }
        if ($v4 !== null) {
            $extra80 .= "    listen {$v4}:80;\n";
            $extra443 .= "    listen {$v4}:443 ssl;\n";
        }
        $config = str_replace(
            "    listen 80;\n    listen [::]:80;\n",
            "    listen 80;\n    listen [::]:80;\n" . $extra80,
            $config
        );
        if ($hasCert) {
            $config = str_replace(
                "    listen 443 ssl;\n    listen [::]:443 ssl;\n",
                "    listen 443 ssl;\n    listen [::]:443 ssl;\n" . $extra443,
                $config
            );
        }
        return $config;
    }


    private function cleanIpv4(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $ip) !== 1) {
            return null;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null;
    }


    private function cleanIpv6(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || preg_match('/^[0-9a-fA-F:]+$/', $ip) !== 1) {
            return null;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $ip : null;
    }
}
