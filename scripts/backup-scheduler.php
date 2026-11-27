#!/usr/bin/env php
<?php
/**
 * Backup schedule dispatcher (daily via cron).
 *
 * Enqueues one `backup.scheduled` worker job per due schedule (enabled,
 * next_run_at passed). Idempotent: skips schedules with an already
 * pending/running job, and never enqueues twice for the same window.
 * The worker (root) does the heavy lifting: create → upload → prune.
 *
 *   php scripts/backup-scheduler.php [--force]
 *
 * --force ignores next_run_at (queues every enabled schedule once, still
 * skipping ones with a live job). For manual runs and the e2e suite.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

if (!module_enabled('backups')) {
    fwrite(STDERR, "backups module is disabled; scheduler skipped\n");
    exit(0);
}

$force = in_array('--force', $argv ?? [], true);
$now = time();
$queued = 0;
$skipped = 0;

foreach (app('db')->select('SELECT * FROM `backup_schedules` WHERE `enabled` = 1 ORDER BY `id`') as $sched) {
    $sid = (int) $sched['id'];
    if (!$force && (int) ($sched['next_run_at'] ?? PHP_INT_MAX) > $now) {
        $skipped++;
        continue;
    }
    $live = null;
    try {
        $live = app('db')->selectOne(
            "SELECT `id` FROM `jobs` WHERE `type` = 'backup.scheduled' AND `status` IN ('pending','running')
             AND JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.schedule_id')) = ?",
            [(string) $sid]
        );
    } catch (\Throwable $e) {
        // Older MariaDB without full JSON functions: LIKE fallback below.
    }
    if ($live === null) {
        $live = app('db')->selectOne(
            "SELECT `id` FROM `jobs` WHERE `type` = 'backup.scheduled' AND `status` IN ('pending','running')
             AND `payload` LIKE ?",
            ['%"schedule_id":' . $sid . '%']
        );
    }
    if ($live !== null) {
        echo "schedule {$sid}: job already queued, skipping\n";
        $skipped++;
        continue;
    }
    app('db')->insert('jobs', [
        'type' => 'backup.scheduled',
        'status' => 'pending',
        'payload' => json_encode(['schedule_id' => $sid]),
        'result' => null,
        'attempts' => 0,
        'max_attempts' => 2,
        'available_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    // Advance next_run_at now so a stuck worker can't cause pile-ups; the
    // run itself records the real outcome in last_status.
    $freq = $sched['frequency'] ?? 'daily';
    app('db')->update('backup_schedules', [
        'next_run_at' => \Modules\Backups\Services\BackupService::nextRun($freq, $now),
        'updated_at' => date('Y-m-d H:i:s'),
    ], '`id` = ?', [$sid]);
    echo "schedule {$sid}: queued\n";
    $queued++;
}

echo "done: {$queued} queued, {$skipped} skipped\n";
