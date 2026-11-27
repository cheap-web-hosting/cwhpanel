<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 20 unattended setup (Question2Answer solo).
 * DB credentials via qa-config.php, then a driver bootstrapping Q2A
 * headless ($qa_autoconnect=false + lenient fail handler for the empty
 * database): install_tables builds the schema and the stock
 * create_new_user mints the level-120 super admin (bcrypt). Pretty URLs
 * ride the page_param machinery (?qa=<path>).
 */
class UpdateBatch20Apps extends Migration {
    public function up(): void
    {
        $this->merge('q2a', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'gd', 'mbstring', 'json']],
            'database' => ['required' => true, 'prefix' => 'qa_'],
            'config' => [],
            'setup' => [
                'files' => [
                    'qa-config.php' => <<<'QACONFIG'
<?php
define('QA_MYSQL_HOSTNAME', '{db_host}');
define('QA_MYSQL_USERNAME', '{db_user}');
define('QA_MYSQL_PASSWORD', '{db_pass}');
define('QA_MYSQL_DATABASE', '{db_name}');
define('QA_MYSQL_TABLE_PREFIX', '{prefix}');
define('QA_EXTERNAL_USERS', false);
QACONFIG,
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 4, '');
$email = trim($args[1]);
$user = trim($args[2]);
$pass = $args[3] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <email> <username> <password>\n");
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
define('QA_BASE_DIR', __DIR__ . '/');
// (QA_EXTERNAL_USERS comes from qa-config.php below — defining it here
// too would only emit a duplicate-constant warning.)
// Skip the autoconnect (it would die on the empty database); connect
// below with a handler that tolerates missing tables.
$qa_autoconnect = false;
require __DIR__ . '/qa-include/qa-base.php';
function qapanel_fail_handler($type, $errno = null, $error = null, $query = null) {
    return null;
}
qa_db_connect('qapanel_fail_handler');
require_once QA_INCLUDE_DIR . 'db/install.php';
qa_db_install_tables();
require_once QA_INCLUDE_DIR . 'app/users-edit.php';
$uid = qa_create_new_user($email, $pass, $user, QA_USER_LEVEL_SUPER, true);
if (empty($uid)) {
    fwrite(STDERR, "admin creation failed\n");
    exit(1);
}
echo "installed admin {$uid}\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{email}', '{user}', '{pass}']],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'page_param' => 'qa',
            'post_install' => 'Installed automatically with the super admin account you entered — log in at /login.',
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
