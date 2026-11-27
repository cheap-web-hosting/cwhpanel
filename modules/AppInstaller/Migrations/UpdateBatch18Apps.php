<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 18 unattended setup (WonderCMS solo, password-only login).
 * The driver reuses the app's own DB layer through its includable
 * index.php (PHPUNIT_TESTING guard): the constructor's getDb() builds
 * data/database.js on fresh trees, then the driver sets the site title +
 * bcrypt password. The app routes via ?page=<slug> (hence page_param)
 * and its credential store needs deny_paths — both generator keys shipped
 * in the same batch.
 */
class UpdateBatch18Apps extends Migration {
    public function up(): void
    {
        $this->merge('wondercms', [
            'type' => 'php',
            'requirements' => ['php_min' => '7.4', 'extensions' => ['curl', 'mbstring', 'zip', 'json', 'openssl']],
            'database' => ['required' => false],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 3, '');
$title = trim($args[1]);
$pass = $args[2] ?? '';
if ($title === '' || strlen($pass) < 8) {
    fwrite(STDERR, "usage: panel-setup.php <title> <password>\n");
    exit(1);
}
define('PHPUNIT_TESTING', true);
// Headless $_SERVER (a URL helper reads these during bootstrap).
$_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
chdir(__DIR__);
require __DIR__ . '/index.php';
// Fresh tree: the constructor's getDb() builds data/database.js itself
// (random password, discarded) — no explicit createDb() (it would swap
// the in-memory object graph for raw arrays and break set()).
$wcms = new Wcms();
$wcms->set('config', 'siteTitle', mb_substr($title, 0, 200));
$wcms->set('config', 'password', password_hash($pass, PASSWORD_DEFAULT));
echo "installed\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{pass}']],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'page_param' => 'page',
            'deny_paths' => ['data/database.js', 'data/cache.json', 'data/security.json'],
            'post_install' => 'Installed automatically — log in at /loginURL with the password you entered.',
        ]);
    }

    /**
     * @param array<string,mixed> $keys
     */
    private function merge(string $slug, array $keys): void
    {
        try {
            $row = $this->db->selectOne('SELECT * FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return;
        }
        if ($row === null) {
            return;
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        if (!is_array($manifest)) {
            $manifest = [];
        }
        foreach ($keys as $k => $v) {
            $manifest[$k] = $v;
        }
        try {
            $this->db->update('ai_applications', [
                'manifest' => json_encode($manifest),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`slug` = ?', [$slug]);
        } catch (\Throwable $e) {
        }
    }
}
