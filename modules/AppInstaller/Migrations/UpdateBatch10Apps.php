<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 10 unattended setups. osTicket (no CLI installer ships with 1.18:
 * drive the upstream browser wizard headless via a one-shot self-deleting
 * panel-setup.php — schema + default data + admin + welcome ticket +
 * config rewrite, all by its own code; setup/ removed by cleanup) and
 * Elgg (elgg-cli install from a generated config file under throwaway
 * elgg-setup/, removed by cleanup; dataroot outside the docroot).
 */
class UpdateBatch10Apps extends Migration {
    public function up(): void
    {
        $this->merge('osticket', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'json', 'openssl', 'imap']],
            'database' => ['required' => true, 'prefix' => 'ost_'],
            'download' => ['github' => ['repo' => 'osTicket/osTicket', 'asset' => 'osTicket-v{version}.zip', 'tag_prefix' => 'v'], 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'strip_dir' => 'upload'],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 12, '');
$title = trim($args[1]);
$sysemail = trim($args[2]);
$fname = trim($args[3]);
$lname = trim($args[4]);
$adminemail = trim($args[5]);
$username = trim($args[6]);
$passwd = $args[7];
$dbhost = trim($args[8]);
$dbname = trim($args[9]);
$dbuser = trim($args[10]);
$dbpass = $args[11];
if ($title === '' || !filter_var($sysemail, FILTER_VALIDATE_EMAIL)
    || $fname === '' || $lname === '' || !filter_var($adminemail, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $username) || strlen($passwd) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <sysemail> <fname> <lname> <adminemail> <username> <passwd> <dbhost> <dbname> <dbuser> <dbpass>\n");
    exit(1);
}
chdir(__DIR__);
// The installer's own bootstrap (paths, validator, translations, DB
// layer). NOT Bootstrap::loadCode(): that pulls filter classes whose table
// constants don't exist until the installer defines them below.
require __DIR__ . '/setup/setup.inc.php';
require __DIR__ . '/setup/inc/class.installer.php';
$config = INCLUDE_DIR . 'ost-config.php';
if (!file_exists($config) && file_exists(INCLUDE_DIR . 'ost-sampleconfig.php')) {
    copy(INCLUDE_DIR . 'ost-sampleconfig.php', $config);
}
$installer = new Installer($config);
$ok = $installer->install(array(
    'name' => $title,
    'email' => $sysemail,
    'fname' => $fname,
    'lname' => $lname,
    'admin_email' => $adminemail,
    'username' => $username,
    'passwd' => $passwd,
    'passwd2' => $passwd,
    'prefix' => 'ost_',
    'dbhost' => $dbhost,
    'dbname' => $dbname,
    'dbuser' => $dbuser,
    'dbpass' => $dbpass,
    'lang_id' => 'en_US',
));
if (!$ok) {
    foreach ((array) $installer->getErrors() as $e) {
        fwrite(STDERR, (is_string($e) ? $e : print_r($e, true)) . "\n");
    }
    exit(1);
}
echo "installed\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', 'support@{domain}', '{user}', 'Admin', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}']],
                ],
                'cron' => [
                    ['schedule' => '*/5 * * * *', 'command' => '{phpbin} {dest}/api/cron.php'],
                ],
                'cleanup' => ['setup'],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /scp/. Mail fetching runs every 5 minutes via cron.',
        ]);

        $this->merge('elgg', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.3', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'json', 'openssl', 'intl']],
            'database' => ['required' => true, 'prefix' => 'elgg_'],
            'config' => [],
            'setup' => [
                'files' => ['elgg-setup/install-config.php' => <<<'ELGGCONF'
<?php
return array(
    'displayname' => '{user}',
    'username' => '{user}',
    'password' => '{pass}',
    'email' => '{email}',
    'dbhost' => '{db_host}',
    'dbport' => '3306',
    'dbuser' => '{db_user}',
    'dbpassword' => '{db_pass}',
    'dbname' => '{db_name}',
    'dbprefix' => '{prefix}',
    'sitename' => '{title}',
    'siteemail' => '{email}',
    'wwwroot' => '{url}/',
    'dataroot' => '{home}/elggdata-{dir}',
    'timezone' => 'UTC',
);
ELGGCONF,
                ],
                'cli' => [
                    ['script' => 'vendor/bin/elgg-cli', 'args' => ['install', '--config={dest}/elgg-setup/install-config.php', '--no-interaction']],
                ],
                'cron' => [
                    ['schedule' => '* * * * *', 'command' => '/usr/bin/curl -fsS -o /dev/null {url}/cron/minute/'],
                    ['schedule' => '*/5 * * * *', 'command' => '/usr/bin/curl -fsS -o /dev/null {url}/cron/fiveminute/'],
                    ['schedule' => '@hourly', 'command' => '/usr/bin/curl -fsS -o /dev/null {url}/cron/hourly/'],
                    ['schedule' => '@daily', 'command' => '/usr/bin/curl -fsS -o /dev/null {url}/cron/daily/'],
                ],
                'datadirs' => ['elggdata-{dir}'],
                'cleanup' => ['elgg-setup', 'install.php', 'upgrade.php'],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. Background cron (minute/hourly/daily) is enabled automatically.',
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
