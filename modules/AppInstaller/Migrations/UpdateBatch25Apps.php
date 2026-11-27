<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 25 unattended setup (PHP Server Monitor solo).
 * DB defines via config.php, then a driver bootstrapping PSM headless
 * (PSM_INSTALL skips the redirect-or-die block): Installer::install()
 * builds the schema + seed rows, and the admin row goes in with bcrypt
 * directly (mirroring changePassword's final state). install.php is
 * removed by cleanup.
 */
class UpdateBatch25Apps extends Migration {
    public function up(): void
    {
        $this->merge('phpservermon', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'mbstring', 'xml', 'json']],
            'database' => ['required' => true, 'prefix' => 'monitor_'],
            'config' => [],
            'setup' => [
                'files' => [
                    'config.php' => <<<'PSMCONFIG'
<?php
define('PSM_DB_PREFIX', '{prefix}');
define('PSM_DB_USER', '{db_user}');
define('PSM_DB_PASS', '{db_pass}');
define('PSM_DB_NAME', '{db_name}');
define('PSM_DB_HOST', '{db_host}');
define('PSM_DB_PORT', '3306');
define('PSM_BASE_URL', '{url}');
define('PSM_WEBCRON_KEY', '');
PSMCONFIG,
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 8, '');
$user = trim($args[1]);
$pass = $args[2] ?? '';
$email = trim($args[3] ?? '');
$dbhost = trim($args[4]);
$dbname = trim($args[5]);
$dbuser = trim($args[6]);
$dbpass = $args[7] ?? '';
if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <user> <pass> <email> <dbhost> <dbname> <dbuser> <dbpass>\n");
    exit(1);
}
define('PSM_INSTALL', true);
chdir(__DIR__);
require __DIR__ . '/src/bootstrap.php';
$db = new \psm\Service\Database($dbhost, $dbuser, $dbpass, $dbname, '3306');
$installer = new \psm\Util\Install\Installer($db);
$installer->install();
echo "schema done\n";
// Admin row with bcrypt directly (mirrors changePassword final state);
// the installer seeds everything else (servers, config, version).
$pdo = new \PDO(
    'mysql:host=' . $dbhost . ';dbname=' . $dbname . ';charset=utf8',
    $dbuser,
    $dbpass,
    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
);
$stmt = $pdo->prepare(
    'INSERT INTO `' . PSM_DB_PREFIX . 'users`'
    . ' (`user_name`, `password`, `level`, `name`, `mobile`, `pushover_key`,'
    . ' `pushover_device`, `telegram_id`, `jabber`, `email`)'
    . ' VALUES (?, ?, 10, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$user, password_hash($pass, PASSWORD_BCRYPT), $user, '', '', '', '', '', $email]);
echo "installed admin\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{user}', '{pass}', '{email}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}']],
                ],
                'cleanup' => ['panel-setup.php', 'install.php'],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL, then add servers to monitor.',
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
