<?php

declare(strict_types=1);

/**
 * Install the Node.js runtimes the panel offers (primary 26 + 20 / 22 LTS).
 *
 * Node 26 is the primary runtime and lives as real files under /usr/local;
 * 20/22 live under /opt/node-<major>. A fresh Rocky box only ships the distro
 * nodejs package (16), so without this every Node app fails with "node
 * runtime is not installed" until an administrator clicks install. turn-up
 * calls this (best effort) so the advertised runtime matrix works out of the
 * box.
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
// 26 first: it is the default runtime for new Node apps.
foreach (['26', '20', '22'] as $major) {
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
