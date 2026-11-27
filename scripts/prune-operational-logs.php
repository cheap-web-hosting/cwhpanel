<?php

declare(strict_types=1);

/**
 * prune-operational-logs.php — retention for unbounded operational tables.
 *
 * provisioning_logs grows with every agent operation (visible: 57k rows /
 * 68MB after one stress day) and audit_logs accumulates forever; neither
 * has a retention policy. Keeps only the rolling window (RETAIN_DAYS).
 *
 * Run daily via deploy/cwhpanel-logprune.cron. Idempotent; deletes only
 * completed/old rows and never touches open monitoring incidents.
 */

require __DIR__ . '/_bootstrap.php';

$app = new App\Foundation\Application(BASE_PATH);
$app->bootstrap();

$tables = [
    'provisioning_logs' => 90,
    'audit_logs' => 365,
];

foreach ($tables as $table => $days) {
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
    try {
        $deleted = (int) app('db')->delete($table, '`created_at` < ?', [$cutoff]);
        echo "pruned {$table}: deleted {$deleted} rows older than {$days}d\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "prune {$table} failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}
