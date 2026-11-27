#!/usr/bin/env php
<?php
/**
 * MySQL statement-time enforcement sweep (every minute via cron).
 *
 * KILL QUERYs any running statement that exceeds the account package's
 * `mysql_max_statement_sec`, logging each kill to `mysql_governor_events`.
 * Per-hour connection/query/update caps are enforced by MariaDB itself when
 * the panel applies the package to a database user; only the running-statement
 * killer needs a periodic sweep.
 *
 * The free edition has no Watchdog module, so this replaces the old
 * `watch.mysql-governor` check with a plain cron entry.
 *
 *   php scripts/mysql-governor-sweep.php
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;
use Modules\Databases\Services\MysqlGovernorService;

$app = new Application(BASE_PATH);
$app->bootstrap();

$stats = app(MysqlGovernorService::class)->sweep();
printf(
    "mysql-governor-sweep: checked=%d killed=%d%s\n",
    $stats['checked'] ?? 0,
    $stats['killed'] ?? 0,
    !empty($stats['top']) ? ' top=' . $stats['top'] : ''
);