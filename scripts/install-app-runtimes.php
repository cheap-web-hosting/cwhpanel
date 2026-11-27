<?php

declare(strict_types=1);

/**
 * Install the side-by-side Node.js runtimes the panel offers (20 / 22 LTS).
 *
 * The primary runtime (26) is installed by turn-up as real files under
 * /usr/local. Node 20/22 live under /opt/node-<major> and are normally
 * installed from Admin → Runtimes; a fresh box does not have them, so every
 * app that selects one fails with "node runtime is not installed" until an
 * administrator clicks install. turn-up calls this (best effort) so the
 * advertised runtime matrix works out of the box.
 *
 * Usage: php scripts/install-app-runtimes.php [--quiet]
 */

require __DIR__ . '/_bootstrap.php';

$app = new \App\Foundation\Application(BASE_PATH);
$app->bootstrap();

$quiet = in_array('--quiet', $argv ?? [], true);
$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

$svc = app(\Modules\Applications\Services\AppService::class);
$installed = $svc->installedRuntimes();
$have = $installed['node'] ?? [];

$failed = 0;
foreach (['20', '22'] as $major) {
    if (in_array($major, $have, true)) {
        $say("node {$major}: already installed");
        continue;
    }
    try {
        [$exact, $sha] = \Modules\Applications\Services\AppService::resolveNode($major);
        $svc->installRuntime('node', $major, $exact, $sha);
        $say("node {$major}: installed {$exact}");
    } catch (\Throwable $e) {
        $say("node {$major}: SKIP (" . $e->getMessage() . ")");
        $failed = 1;
    }
}

exit($failed === 0 ? 0 : 1);
