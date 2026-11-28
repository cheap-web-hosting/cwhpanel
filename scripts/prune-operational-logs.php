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

// File-manager archives live outside the account quota; keep only a short
// retention window (the per-account keep-count runs when a new archive is
// created). Best-effort: a failure here must not fail the log prune.
try {
    $r = app('provisioning')->execute('file.archive.prune', ['days' => 7]);
    echo "pruned file-manager archives older than 7d: " . (string) ($r['status'] ?? 'unknown') . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'prune file-manager archives failed: ' . $e->getMessage() . "\n");
}

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
