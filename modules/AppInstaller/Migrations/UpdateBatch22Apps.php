<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 22 unattended setup (Monstra solo, no database).
 * The driver bootstraps the engine headless (BACKEND=true skips the Site
 * front controller) and replays install.php's option updates + admin
 * insert with Monstra's own classes. PHP 7.4 only (the engine calls
 * get_magic_quotes_gpc(), removed in PHP 8.0) — hence php_max plus a
 * pinned php_version CLI step.
 */
class UpdateBatch22Apps extends Migration {
    public function up(): void
    {
        $this->merge('monstra', [
            'type' => 'php',
            'requirements' => ['php_min' => '7.4', 'php_max' => '7.99', 'extensions' => ['curl', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => false],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
$title = trim($args[1]);
$user = trim($args[2]);
$pass = $args[3] ?? '';
$email = trim($args[4] ?? '');
if ($title === '' || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user)
    || strlen($pass) < 8 || !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <login> <password> <email>\n");
    exit(1);
}
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', __DIR__);
define('BACKEND', true);
define('MONSTRA_ACCESS', true);
$_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
require_once(ROOT . DS . 'engine' . DS . '_init.php');
Option::update(array('maintenance_status' => 'off',
    'sitename' => mb_substr($title, 0, 200),
    'siteurl' => rtrim('{url}', '/'),
    'description' => 'Site description',
    'keywords' => 'Site keywords',
    'slogan' => 'Site slogan',
    'defaultpage' => 'home',
    'timezone' => 'UTC',
    'system_email' => $email,
    'theme_site_name' => 'default',
    'theme_admin_name' => 'default'));
$users = new Table('users');
$id = $users->insert(array('login' => $user,
    'password' => Security::encryptPassword($pass),
    'email' => $email,
    'hash' => Text::random('alnum', 12),
    'date_registered' => time(),
    'role' => 'admin'));
if (empty($id)) {
    fwrite(STDERR, "admin creation failed\n");
    exit(1);
}
echo "installed admin\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{user}', '{pass}', '{email}'], 'php_version' => '74'],
                ],
                'cleanup' => ['panel-setup.php', 'install.php'],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /admin.',
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
