<?php
declare(strict_types=1);

/**
 * PanelManager heartbeat — reports this installation's state (hostname, IP,
 * panel version, hosted account count, license type) to the central dashboard
 * and applies any policy directives (ban status, updates blocked).
 *
 * Intended to run from cron, hourly:
 *   17 * * * * root /usr/bin/nice -n 10 /usr/bin/php __PANEL_DIR__/scripts/panel-manager-report.php >> /var/log/cwhpanel-panelmanager.log 2>&1
 *
 * Safe to run any time; no-op when PANEL_MANAGER_URL is not configured.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

try {
    $svc = app(\Modules\Core\Services\PanelManagerService::class);
    if (!$svc->configured()) {
        echo "panel manager: not configured (PANEL_MANAGER_URL unset) — skipped\n";
        exit(0);
    }
    $result = $svc->sync();
    $status = $svc->status();
    $line = 'panel manager sync: ' . json_encode($result);
    if ($status !== null) {
        $line .= ' last=' . ($status['at'] ?? '') . ' http=' . ($status['http_status'] ?? '-');
        if (($status['banned'] ?? false) === true) {
            $line .= ' BANNED';
        }
        if ($svc->policyBlocked()) {
            $line .= ' updates-blocked';
        }
    }
    echo $line . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'panel-manager-report error: ' . $e->getMessage() . "\n");
    exit(1);
}
