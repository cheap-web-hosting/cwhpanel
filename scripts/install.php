<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Foundation\Application;
use App\Foundation\Database\Migrator;
use App\Foundation\Env;

$env = Env::load(BASE_PATH . '/.env');

$host = $env->get('DB_HOST', '127.0.0.1');
$port = (int) $env->get('DB_PORT', 3306);
$database = $env->get('DB_DATABASE', 'cwhpanel');
$username = $env->get('DB_USERNAME', 'root');
$password = $env->get('DB_PASSWORD', '');

echo "Ensuring database [{$database}] exists...\n";

$dbName = str_replace('`', '', $database);
try {
    // Prefer the root unix socket (this is a root-run CLI installer): the
    // panel account is scoped (M-7) and cannot CREATE an arbitrary schema.
    $pdo = null;
    try {
        $pdo = new PDO('mysql:unix_socket=/var/lib/mysql/mysql.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    // When a non-root panel user will run the migrations, it needs ALL on the
    // (possibly brand-new) schema. On the live cwhpanel DB this is already
    // granted; this covers throwaway/renamed databases and first installs.
    if ($username !== '' && strtolower($username) !== 'root') {
        foreach (["'{$username}'@'localhost'", "'{$username}'@'127.0.0.1'"] as $acct) {
            try { $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO {$acct}"); } catch (Throwable $e) {}
        }
        try { $pdo->exec('FLUSH PRIVILEGES'); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not connect to MySQL: ' . $e->getMessage() . "\n");
    exit(1);
}

$app = new Application(BASE_PATH);
$app->bootstrap();

$migrator = new Migrator(app('db'));
$migrations = $app->moduleManager()->migrations();

echo "Running migrations...\n";
$ran = $migrator->run($migrations);

if ($ran === []) {
    echo "Nothing to migrate.\n";
} else {
    foreach ($ran as $migration) {
        echo "Migrated: {$migration}\n";
    }
}

echo "\n";
require __DIR__ . '/seed.php';
