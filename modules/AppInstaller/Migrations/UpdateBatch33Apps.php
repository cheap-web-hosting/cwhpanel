<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 33 unattended setup (Omeka S solo).
 * No CLI installer ships: the driver boots Laminas headless and calls the
 * stock Installation Installer service (the same one the web
 * InstallController uses) — schema + vocabularies + templates + first
 * admin + settings. database.ini is static setup.files text (only
 * panel-generated hex secrets ride it); the wizard password rides argv.
 * Proven live on a hostddl.net subdir: install, front 200, browser login
 * into the admin dashboard, full uninstall.
 */
class UpdateBatch33Apps extends Migration {
    public function up(): void
    {
        $this->merge('omeka-s', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['pdo_mysql', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'fileinfo']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['config/database.ini' => <<<'PANELCONFIG'
user     = "{db_user}"
password = "{db_pass}"
dbname   = "{db_name}"
host     = "{db_host}"
PANELCONFIG,
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass>\n");
    exit(1);
}
// The agent runs setup scripts without cd-ing into the tree.
chdir(__DIR__);
require __DIR__ . '/bootstrap.php';
$app = Omeka\Mvc\Application::init(require __DIR__ . '/application/config/application.config.php');
$installer = $app->getServiceManager()->get('Omeka\Installer');
$installer->registerVars('Omeka\Installation\Task\CreateFirstUserTask', [
    'name' => $user,
    'email' => $email,
    'password-confirm' => ['password' => $pass],
]);
$installer->registerVars('Omeka\Installation\Task\AddDefaultSettingsTask', [
    'administrator_email' => $email,
    'installation_title' => $title,
    'time_zone' => 'UTC',
    'locale' => 'en_US',
]);
if (!$installer->install()) {
    foreach ((array) $installer->getErrors() as $e) {
        fwrite(STDERR, (is_string($e) ? $e : print_r($e, true)) . "\n");
    }
    exit(1);
}
echo "omeka setup complete\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}']],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'admin' => ['path' => 'admin'],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /admin/.',
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
