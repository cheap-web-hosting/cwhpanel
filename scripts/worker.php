<?php
declare(strict_types=1);

/**
 * Background worker: consumes the job queue and delivers webhooks.
 *
 *   php scripts/worker.php [--once]
 *
 * Without --once it loops forever (run under systemd/supervisor/cron). Each tick:
 *   - claims pending jobs (status=pending), runs their handler, records result
 *   - delivers queued webhook deliveries (type=webhook.deliver)
 *
 * Job types are mapped to handlers via Modules\Jobs\Services\JobRegistry.
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;
use Modules\Jobs\Services\JobRegistry;
use Modules\Jobs\Services\JobRunner;

$app = new Application(BASE_PATH);
$app->bootstrap();

$runner = new JobRunner(app('db'));
$once = in_array('--once', $argv, true);

echo "worker started (pid " . getmypid() . ")\n";

do {
    try {
        $processed = $runner->tick();
        if ($processed['jobs'] === 0 && $processed['webhooks'] === 0) {
            if ($once) {
                break;
            }
            sleep(2);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'worker error: ' . $e->getMessage() . "\n");
        sleep(2);
    }
} while (!$once);

echo "worker exiting\n";