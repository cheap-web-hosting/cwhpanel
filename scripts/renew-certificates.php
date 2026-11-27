<?php
declare(strict_types=1);

/**
 * Auto-renew expiring ACME certificates (auto_renew=1 within threshold).
 *
 * Intended to run from cron, e.g. once daily:
 *   2 1 * * * /usr/bin/php __PANEL_DIR__/scripts/renew-certificates.php
 *
 *   php scripts/renew-certificates.php [--threshold=30]
 */

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;

$app = new Application(BASE_PATH);
$app->bootstrap();

if (!module_enabled('ssl')) {
    fwrite(STDERR, "ssl module is disabled; renewal skipped\n");
    exit(0);
}

$threshold = 30;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--threshold=')) {
        $threshold = (int) substr($arg, strlen('--threshold='));
    }
}

try {
    $result = app(\Modules\Ssl\Services\SslService::class)->renewDue($threshold);
} catch (\Throwable $e) {
    fwrite(STDERR, 'renew-certificates error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "ssl renew sweep: {$result['renewed']} renewed, {$result['failed']} failed, " . ($result['skipped'] ?? 0) . " skipped (rate-limit backoff)\n";

foreach ($result['results'] as $r) {
    $extra = $r['error'] ?? ($r['retry_after'] ?? null ? "retry after {$r['retry_after']}" : '');
    echo "  [{$r['status']}] {$r['domain']}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
}

exit(0);