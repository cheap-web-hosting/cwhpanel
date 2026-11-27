<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 47: add FreshRSS (self-hosted RSS reader, php-mysql) as application
 * #94. Installs via cli/do-install.php, then cli/create-user.php (driver
 * mirrors the seed copy in Seed\CatalogApps byte for byte).
 */
class UpdateBatch47Freshrss extends Migration {

    public function up(): void
    {
        if ($this->db->selectOne('SELECT id FROM ai_applications WHERE slug = ?', ['freshrss'])) {
            return;
        }

        $manifest = [
            'type' => 'php-mysql',
            'requirements' => [
                'php_min' => '8.1',
                'extensions' => ['pdo_mysql','curl','dom','fileinfo','intl','mbstring','openssl','simplexml','zip','gmp'],
            ],
            'database' => [
                'required' => true,
                'prefix' => '',
            ],
            'download' => [
                'url' => 'https://github.com/FreshRSS/FreshRSS/archive/refs/tags/{version}.tar.gz',
                'sha256' => '',
                'format' => 'tar',
                'strip_root' => true,
            ],
            'config' => [],
            'setup' => [
                'files' => [
                    'panel-setup.php' => <<<'PANELSETUP'
<?php

/**
 * FreshRSS unattended installer driver (runs as the account user via the
 * agent). Discrete argv (no shell, no JSON quoting — the panel interpolates
 * each placeholder into its own argument):
 *   1 db_host  2 db_name  3 db_user  4 db_pass  5 user  6 pass  7 base_url
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$args = array_slice($argv ?? [], 1);
if (count($args) < 6) {
    fwrite(STDERR, "freshrss setup: bad arguments\n");
    exit(1);
}
$in = [
    'db_host' => (string) $args[0],
    'db_name' => (string) $args[1],
    'db_user' => (string) $args[2],
    'db_pass' => (string) $args[3],
    'user' => (string) $args[4],
    'pass' => (string) $args[5],
    'url' => isset($args[6]) ? (string) $args[6] : '',
];

foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'user', 'pass'] as $k) {
    if ($in[$k] === '') {
        fwrite(STDERR, "freshrss setup: missing {$k}\n");
        exit(1);
    }
    if (str_contains($in[$k], "\n") || str_contains($in[$k], "\0")) {
        fwrite(STDERR, "freshrss setup: invalid {$k}\n");
        exit(1);
    }
}

if (function_exists('posix_getuid') && posix_getuid() === 0) {
    fwrite(STDERR, "freshrss setup: refusing to run as root\n");
    exit(1);
}

$marker = __DIR__ . '/data/applied_migrations.txt';
if (is_file($marker)) {
    echo "freshrss setup: already installed, skipping\n";
    exit(0);
}

$baseUrl = 'http://localhost';
if (isset($in['url']) && is_string($in['url']) && $in['url'] !== '') {
    $baseUrl = $in['url'];
}

$rc1 = 1;
passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cli/do-install.php')
    . ' --db-type mysql'
    . ' --db-host ' . escapeshellarg($in['db_host'])
    . ' --db-user ' . escapeshellarg($in['db_user'])
    . ' --db-password ' . escapeshellarg($in['db_pass'])
    . ' --db-base ' . escapeshellarg($in['db_name'])
    . ' --base-url ' . escapeshellarg($baseUrl)
    . ' --default-user ' . escapeshellarg($in['user'])
    . ' --auth-type form'
    . ' --environment production',
    $rc1
);
if ($rc1 !== 0 && !is_file($marker)) {
    fwrite(STDERR, "freshrss setup: installer failed (exit {$rc1})\n");
    exit(1);
}

$rc2 = 1;
passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cli/create-user.php')
    . ' --user ' . escapeshellarg($in['user'])
    . ' --password ' . escapeshellarg($in['pass']),
    $rc2
);
if ($rc2 !== 0) {
    fwrite(STDERR, "freshrss setup: create-user failed (exit {$rc2})\n");
    exit(1);
}

echo "freshrss setup complete\n";
exit(0);
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{user}', '{pass}', '{url}']],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'webroot' => 'p',
            'admin' => ['path' => ''],
            'update_check' => ['type' => 'github', 'repo' => 'FreshRSS/FreshRSS'],
        ];

        $this->db->insert('ai_applications', [
            'slug' => 'freshrss',
            'name' => 'FreshRSS',
            'category' => 'Media',
            'version' => '1.30.1',
            'description' => 'Self-hosted RSS aggregator with full-text, mobile UI, OPML import/export.',
            'website' => 'https://freshrss.org',
            'license' => 'AGPL-3.0',
            'vendor' => 'FreshRSS',
            'manifest' => json_encode($manifest),
            'is_active' => 1,
            'is_featured' => 0,
            'sort_order' => 100,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
