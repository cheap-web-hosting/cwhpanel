#!/usr/bin/env php
<?php
/**
 * Backfill `webmail.<zone>` A/AAAA records for every active zone so webmail
 * points at the panel's Roundcube front door (see DomainService::provisionDns
 * which adds them on new/addon/parked domain provisioning).
 *
 * Run from the repo root: php scripts/add-webmail-records.php
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$dns = app(\Modules\Dns\Services\DnsService::class);
$serverIp = (string) env('PANEL_PUBLIC_IP', '68.168.20.43');

$zones = app('db')->select(
    'SELECT z.`name`, z.`account_id`, a.`ipv6_address`
     FROM `dns_zones` z
     LEFT JOIN `accounts` a ON a.`id` = z.`account_id`
     WHERE z.`status` = ? ORDER BY z.`name`',
    ['active']
);

$n = 0;
foreach ($zones as $zone) {
    $name = (string) $zone['name'];
    $v6 = trim((string) ($zone['ipv6_address'] ?? ''));
    try {
        $dns->upsertRecord($name, 'webmail.' . $name, 'A', $serverIp);
        if ($v6 !== '' && filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $dns->upsertRecord($name, 'webmail.' . $name, 'AAAA', $v6);
        }
        $n++;
        echo "  webmail.$name -> $serverIp" . ($v6 !== '' ? " / $v6" : '') . "\n";
    } catch (\Throwable $e) {
        echo "  FAIL webmail.$name: " . $e->getMessage() . "\n";
    }
}

echo "\nAdded/updated webmail records for {$n} zones.\n";
