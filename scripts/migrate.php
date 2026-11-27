<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;
use App\Foundation\Database\Migrator;

$app = new Application(BASE_PATH);
$app->bootstrap();

$migrator = new Migrator(app('db'));
$migrations = $app->moduleManager()->migrations();

$ran = $migrator->run($migrations);

if ($ran === []) {
    echo "Nothing to migrate — all migrations are already applied.\n";
} else {
    foreach ($ran as $migration) {
        echo "Migrated: {$migration}\n";
    }
}
