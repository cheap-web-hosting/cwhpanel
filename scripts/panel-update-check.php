#!/usr/bin/env php
<?php
/**
 * Panel update auto-check (daily cron).
 *
 * Refreshes the cached update status (installed vs latest release) so the
 * admin Updates page always shows fresh data without a manual "Check now".
 * When a NEW version becomes available (i.e. the latest reported version
 * changed and is newer than installed), pushes an admin notification once.
 *
 * Idempotent + overlap-guarded (flock). Never throws out of the script —
 * a network/feed failure just leaves the previous cache and is logged.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

$lockFp = fopen('/run/cwhpanel/panel-update.lock', 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0); // another check still running
}

try {
    $svc = app(\Modules\Core\Services\PanelUpdateService::class);

    $before = $svc->status();
    $after = $svc->snapshot(true); // refresh against GitHub

    $installed = (string) ($after['installed'] ?? '');
    $latest = isset($after['latest']) ? (string) $after['latest'] : null;
    $upToDate = (bool) ($after['up_to_date'] ?? false);

    if ($latest !== null && !$upToDate) {
        // Auto-apply first when the mode allows it.
        if ($svc->mode() === 'auto') {
            $applyResult = $svc->attemptAutoApply();
            fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] auto-apply: reason={$applyResult['reason']} version=" . ($applyResult['version'] ?? '-') . ' detail=' . ($applyResult['detail'] ?? '-') . "\n");
            // attemptAutoApply already handled notifications/audit.
        } else {
            // Notify once per version: only when the latest reported version
            // actually CHANGED since the previous check (prevents daily spam).
            $prevLatest = $before['latest'] ?? null;
            if ($prevLatest !== $latest) {
                try {
                    app(\Modules\Core\Services\NotificationService::class)->pushAdmin(
                        'system.panel_update',
                        'Panel update available: v' . $latest,
                        'Installed v' . $installed . ' — new release v' . $latest . ' is available.',
                        '/admin/server/updates'
                    );
                } catch (\Throwable $e) {
                    app('logger')->warning('Panel-update notification failed: ' . $e->getMessage());
                }
                fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] new update available: v{$latest} (installed v{$installed})\n");
            } else {
                fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] update still available: v{$latest} (installed v{$installed})\n");
            }
        }
    } else {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] panel up to date (v{$installed})\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[panel-update-check] failed: ' . $e->getMessage() . "\n");
    app('logger')->error('Panel update auto-check failed: ' . $e->getMessage());
    exit(1);
}
