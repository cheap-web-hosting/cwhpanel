<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 12 unattended setups. Cacti (cli/install_cacti.php with its own
 * Installer class: schema pre-imported by a panel-setup.php driver since
 * the stock CLI refuses an empty database and setup.sql runs after
 * setup.cli; seeded admin/admin rotated to the wizard password via sql_post
 * bcrypt update since phpass verifies it; poller.php every 5 minutes;
 * install/ removed) and ILIAS (cli/setup.php install driven by a
 * self-cleaning panel-setup.php that writes its JSON config to $HOME —
 * ILIAS refuses configs inside the install tree; dataroot outside the
 * docroot, minutely cli/cron.php; first login is root/homer with a forced
 * password change).
 */
class UpdateBatch12Apps extends Migration {
    public function up(): void
    {
        $this->merge('cacti', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'gd', 'mbstring', 'xml', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
                    'setup' => [
                        'files' => ['include/config.php' => <<<'CACTICONF'
<?php
$database_type     = 'mysql';
$database_default  = '{db_name}';
$database_hostname = '{db_host}';
$database_username = '{db_user}';
$database_password = '{db_pass}';
$database_port     = '3306';
$database_retries  = 5;
$database_ssl      = false;
$database_ssl_key  = '';
$database_ssl_cert = '';
$database_ssl_ca   = '';
$database_persist  = false;
$poller_id = 1;
$url_path = '{urlpath}';
$cacti_session_name = 'Cacti';
$cacti_db_session = false;
$disable_log_rotation = false;
$proxy_headers = null;
CACTICONF,
                            'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
$dbhost = trim($args[1]);
$dbname = trim($args[2]);
$dbuser = trim($args[3]);
$dbpass = $args[4];
$sqlFile = __DIR__ . '/cacti.sql';
if ($dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === '' || !is_file($sqlFile)) {
    fwrite(STDERR, "usage: panel-setup.php <dbhost> <dbname> <dbuser> <dbpass>\n");
    exit(1);
}
$cmd = '/usr/bin/mysql -h ' . escapeshellarg($dbhost) . ' -P 3306 -u ' . escapeshellarg($dbuser)
    . ' -p' . escapeshellarg($dbpass) . ' ' . escapeshellarg($dbname)
    . ' < ' . escapeshellarg($sqlFile) . ' 2>&1';
exec($cmd, $out, $code);
echo implode("\n", $out) . "\n";
exit($code === 0 ? 0 : 1);
PANELSETUP,
                        ],
                        'cli' => [
                            ['script' => 'panel-setup.php', 'args' => ['{db_host}', '{db_name}', '{db_user}', '{db_pass}']],
                            ['script' => 'cli/install_cacti.php', 'args' => ['--install', '--accept-eula']],
                        ],
                        'sql_post' => [
                            ['table' => 'user_auth', 'set' => ['password' => '{pass_hash}', 'email_address' => '{email}', 'must_change_password' => ''], 'where' => ['username' => 'admin']],
                        ],
                        'cron' => [
                            ['schedule' => '*/5 * * * *', 'command' => '{phpbin} {dest}/poller.php'],
                        ],
                        'cleanup' => ['install', 'panel-setup.php'],
            ],
            'post_install' => 'Installed automatically — log in as admin with the password you entered. The poller runs every 5 minutes via cron (RRDTool must be installed on the server).',
        ]);

        $this->merge('ilias', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.3', 'php_max' => '8.3', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'imagick']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 11, '');
$dir = trim($args[1], '/');
$dbhost = trim($args[2]);
$dbname = trim($args[3]);
$dbuser = trim($args[4]);
$dbpass = $args[5];
$home = rtrim($args[6], '/');
$url = rtrim(trim($args[7]), '/');
$email = trim($args[8]);
$user = trim($args[9]);
$title = trim($args[10]);
if ($dir === '' || preg_match('#[^A-Za-z0-9._-]#', $dir)
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || $home === '' || $url === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || $user === '' || $title === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <dir> <dbhost> <dbname> <dbuser> <dbpass> <home> <url> <email> <user> <title>\n");
    exit(1);
}
$configPath = $home . '/ilias-setup-' . $dir . '.json';
$conf = array(
    'common' => array('client_id' => $dir),
    'database' => array(
        'host' => $dbhost,
        'port' => 3306,
        'database' => $dbname,
        'user' => $dbuser,
        'password' => $dbpass,
        'create_database' => false,
    ),
    'filesystem' => array('data_dir' => $home . '/iliasdata-' . $dir),
    'http' => array('path' => $url . '/'),
    'systemfolder' => array('contact' => array(
        'firstname' => $user,
        'lastname' => 'Admin',
        'email' => $email,
    )),
);
chdir(__DIR__);
file_put_contents($configPath, json_encode($conf, JSON_PRETTY_PRINT));
chmod($configPath, 0600);
// ILIAS ships wide varchar(4000) tables that overflow the row-size limit
// under utf8mb4 — upstream mandates a utf8 database.
try {
    $pdo = new PDO(
        'mysql:host=' . $dbhost . ';port=3306;charset=utf8mb4',
        $dbuser,
        $dbpass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $pdo->exec('ALTER DATABASE `' . str_replace('`', '', $dbname) . '` CHARACTER SET utf8 COLLATE utf8_general_ci');
} catch (Throwable $e) {
    fwrite(STDERR, 'charset setup failed: ' . $e->getMessage() . "\n");
    @unlink($configPath);
    exit(1);
}
$code = 1;
try {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cli/setup.php')
        . ' install -y --no-interaction ' . escapeshellarg($configPath) . ' 2>&1';
    exec($cmd, $out, $code);
    echo implode("\n", $out) . "\n";
} finally {
    @unlink($configPath);
}
exit($code === 0 ? 0 : 1);
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{dir}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{home}', '{url}', '{email}', '{user}', '{title}']],
                ],
                'cron' => [
                    ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/cli/cron.php'],
                ],
                'datadirs' => ['iliasdata-{dir}'],
                'cleanup' => ['panel-setup.php'],
            ],
            'webroot' => 'public',
            'post_install' => 'Installed automatically — log in as root with password homer, then set a new password. Background jobs run every minute via cron.',
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
