<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 15 unattended setups (project + CRM). Leantime (panel-driven
 * Install service headless: schema + company + inactive admin, then the
 * bcrypt password is set and the account activated via sql_post) and
 * EspoCRM (install/cli.php driven headless step by step — saveSettings,
 * buildDatabase, createUser, finish — with urlencoded -d data built by a
 * driver, since raw & or = in secrets would corrupt static text).
 */
class UpdateBatch15Apps extends Migration {
    public function up(): void
    {
        $this->merge('leantime', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['config/.env' => "LEAN_APP_URL={url}\nLEAN_APP_DIR={scriptpath}\nLEAN_DEBUG=0\nLEAN_DB_HOST={db_host}\nLEAN_DB_USER={db_user}\nLEAN_DB_PASSWORD={db_pass}\nLEAN_DB_DATABASE={db_name}\nLEAN_DB_PORT=3306\nLEAN_SESSION_PASSWORD={app_key}\n",
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
$email = trim($args[1]);
$fname = trim($args[2]);
$lname = trim($args[3]);
$company = trim($args[4]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)
    || $fname === '' || $lname === '' || $company === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <email> <firstname> <lastname> <company>\n");
    exit(1);
}
chdir(__DIR__);
define('LEAN_CLI', true);
define('ARTISAN_BINARY', 'bin/leantime');
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$installer = $app->make(Leantime\Domain\Install\Services\Install::class);
$values = array(
    'email' => $email,
    'firstname' => $fname,
    'lastname' => $lname,
    'company' => $company,
);
try {
    $installer->validateInstallInput($values);
} catch (Throwable $e) {
    fwrite(STDERR, 'invalid install input: ' . $e->getMessage() . "\n");
    exit(1);
}
if (!$installer->runInstall($values)) {
    fwrite(STDERR, "install failed\n");
    exit(1);
}
echo "installed\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{email}', '{user}', 'Admin', '{title}']],
                ],
                'sql_post' => [
                    ['table' => 'zp_user', 'set' => ['password' => '{pass_hash}', 'status' => 'a'], 'where' => ['username' => '{email}']],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'webroot' => 'public',
            'post_install' => 'Installed automatically — log in with your email address at the site URL.',
        ]);

        $this->merge('espocrm', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.3', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 13, '');
$title = trim($args[1]);
$sysemail = trim($args[2]);
$fname = trim($args[3]);
$lname = trim($args[4]);
$adminuser = trim($args[5]);
$adminpass = $args[6];
$dbhost = trim($args[7]);
$dbname = trim($args[8]);
$dbuser = trim($args[9]);
$dbpass = $args[10];
$adminemail = trim($args[11] ?? '');
$siteurl = rtrim(trim($args[12] ?? ''), '/');
if ($title === '' || $fname === '' || $lname === ''
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $adminuser) || strlen($adminpass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !filter_var($adminemail, FILTER_VALIDATE_EMAIL) || $siteurl === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <sysemail> <fname> <lname> <adminuser> <adminpass> <dbhost> <dbname> <dbuser> <dbpass> <adminemail> <siteurl>\n");
    exit(1);
}
chdir(__DIR__);
$run = function ($action, $data = array()) {
    $query = http_build_query(array_merge(array('action' => $action), $data));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install/cli.php')
        . ' -a ' . escapeshellarg($action) . ' -d ' . escapeshellarg($query) . ' 2>&1';
    exec($cmd, $out, $code);
    $text = implode("\n", $out);
    echo $text . "\n";
    if ($code !== 0) {
        return false;
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded) && array_key_exists('success', $decoded) && $decoded['success'] !== true) {
        return false;
    }
    return true;
};
$ok = $run('saveSettings', array(
    'db-name' => $dbname,
    'db-platform' => 'Mysql',
    'db-user-name' => $dbuser,
    'db-user-password' => $dbpass,
    'host-name' => $dbhost,
    'site-url' => $siteurl,
    'user-lang' => 'en_US',
));
$ok = $ok && $run('buildDatabase');
$ok = $ok && $run('createUser', array('user-name' => $adminuser, 'user-pass' => $adminpass));
$ok = $ok && $run('finish');
exit($ok ? 0 : 1);
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', 'Admin', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{email}', '{url}']],
                ],
                'cron' => [
                    ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/cron.php'],
                ],
                'cleanup' => ['install', 'panel-setup.php'],
            ],
            'webroot' => 'public',
            'webroot_static' => ['client'],
            'webroot_routes' => ['api' => 'api/v1/index.php', 'portal' => 'portal/index.php'],
            'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. Background jobs run every minute via cron.',
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
