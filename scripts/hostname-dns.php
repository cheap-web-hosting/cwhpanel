#!/usr/bin/env php
<?php
/**
 * Seed the local PowerDNS with the server hostname's zone + records
 * (A/AAAA, mail A/AAAA, MX, SPF, DMARC, DKIM, NS) so host/cron/root mail
 * authenticates against a DNS server this box already runs. Idempotent.
 *
 * Called by turn-up.sh after pDNS is up, and by the Server → Hostname page's
 * re-provision action.
 *
 * Usage: php scripts/hostname-dns.php [--hostname=h]
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$opts = getopt('', ['hostname:']);
$svc = app(\Modules\Core\Services\HostnameService::class);

try {
    $fqdn = isset($opts['hostname']) && $opts['hostname'] !== ''
        ? $svc->validateFqdn((string) $opts['hostname'])
        : $svc->current();
    $recs = $svc->provisionDns($fqdn);
} catch (\Throwable $e) {
    fwrite(STDERR, 'hostname-dns: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "hostname DNS for {$fqdn}:\n";
foreach ($recs as $r) {
    echo "  - {$r}\n";
}
exit(0);