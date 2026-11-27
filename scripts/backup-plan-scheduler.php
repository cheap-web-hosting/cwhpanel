<?php

declare(strict_types=1);

/**
 * Fleet backup plan scheduler (cron, every 10 minutes).
 *
 * Enqueues one `backup.plan.run` job when the plan is enabled and due. The
 * worker then backs up every active account with the chosen components and
 * prunes to the keep count. Idempotent: a pending/running plan job blocks a
 * second enqueue.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

if (!module_enabled('backups')) {
    fwrite(STDERR, "backups module is disabled; plan scheduler skipped\n");
    exit(0);
}

$svc = app(\Modules\Backups\Services\BackupService::class);
$plan = $svc->plan();

if (!\Modules\Backups\Services\BackupService::planDue($plan, time())) {
    exit(0);
}

$busy = (int) app('db')->scalar(
    "SELECT COUNT(*) FROM `jobs` WHERE `type` = 'backup.plan.run' AND `status` IN ('pending','running')"
);
if ($busy > 0) {
    exit(0);
}

app('db')->insert('jobs', [
    'type' => 'backup.plan.run',
    'status' => 'pending',
    'payload' => json_encode(['plan_id' => (int) $plan['id'], 'scheduled' => true]),
    'result' => null,
    'attempts' => 0,
    'max_attempts' => 1,
    'available_at' => date('Y-m-d H:i:s'),
    'created_at' => date('Y-m-d H:i:s'),
]);

fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] fleet backup plan queued (plan {$plan['id']})\n");