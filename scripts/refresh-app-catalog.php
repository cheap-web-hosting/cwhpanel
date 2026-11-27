#!/usr/bin/env php
<?php
/**
 * App-catalog freshness sweep (weekly via cron).
 *
 * Checks every catalog application with an update source (GitHub Atom,
 * PyPI, npm) for new upstream releases and AUTO-UPDATES versioned rows
 * whose manifests resolve downloads from the version (github-templated).
 * Installed apps are never touched — owners update those per-installation
 * from the panel, deliberately.
 *
 *   php scripts/refresh-app-catalog.php [--force]
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

if (!module_enabled('appinstaller')) {
    fwrite(STDERR, "appinstaller module is disabled; catalog refresh skipped\n");
    exit(0);
}

$force = in_array('--force', $argv ?? [], true);
$svc = app(\Modules\AppInstaller\Services\CatalogUpdateService::class);
$stats = $svc->refreshAll($force);
echo date('Y-m-d H:i:s')
    . " checked={$stats['checked']} auto-updated={$stats['updated']} failed={$stats['failed']}\n";
