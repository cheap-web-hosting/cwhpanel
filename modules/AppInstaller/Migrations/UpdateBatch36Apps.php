<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 36 unattended setup (Providence/CollectiveAccess solo).
 * The driver runs stock caUtils install (default profile, ~40s), then
 * renames the seeded administrator to the wizard username and sets its
 * password through the ca_users model. setup.php is built from
 * setup.php-dist (needle-asserted); background tasks use socket mode
 * (masters disable proc_open) via app/conf/local/app.conf, plus a
 * minutely task-queue cron. Proven live on a hostddl.net subdir:
 * install, front 200, browser login as the renamed admin, full uninstall.
 */
class UpdateBatch36Apps extends Migration {
    public function up(): void
    {
        $this->merge('providence', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['pdo_mysql', 'mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'fileinfo', 'intl']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['app/conf/local/app.conf' => <<<'PANELCONFIG'
background_process_mode = socket
PANELCONFIG,
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 10, '');
$title = trim($args[1]);
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
$dbhost = trim($args[5]);
$dbname = trim($args[6]);
$dbuser = trim($args[7]);
$dbpass = $args[8] ?? '';
$url = rtrim(trim($args[9]), '/');
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbhost === '' || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
// The agent runs setup scripts without cd-ing into the tree.
chdir(__DIR__);
// 1. setup.php from the shipped -dist (assert every needle: a renamed
// upstream key must fail loudly here, not as a mystery later).
$dist = @file_get_contents(__DIR__ . '/setup.php-dist');
if ($dist === false) {
    fwrite(STDERR, "setup.php-dist missing\n");
    exit(1);
}
$esc = static function ($v) {
    return str_replace(['\\', "'", '$'], ['\\\\', "\\'", '\\$'], (string) $v);
};
$subs = [
    "define(\"__CA_DB_HOST__\", 'localhost');" => "define(\"__CA_DB_HOST__\", '" . $esc($dbhost) . "');",
    "define(\"__CA_DB_USER__\", 'my_database_user');" => "define(\"__CA_DB_USER__\", '" . $esc($dbuser) . "');",
    "define(\"__CA_DB_PASSWORD__\", 'my_database_password');" => "define(\"__CA_DB_PASSWORD__\", '" . $esc($dbpass) . "');",
    "define(\"__CA_DB_DATABASE__\", 'name_of_my_database');" => "define(\"__CA_DB_DATABASE__\", '" . $esc($dbname) . "');",
    'define("__CA_APP_DISPLAY_NAME__", "My First CollectiveAccess System");' => 'define("__CA_APP_DISPLAY_NAME__", "' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $title) . '");',
    "define(\"__CA_ADMIN_EMAIL__\", 'info@put-your-domain-here.com');" => "define(\"__CA_ADMIN_EMAIL__\", '" . $esc($email) . "');",
];
foreach ($subs as $needle => $replacement) {
    if (strpos($dist, $needle) === false) {
        fwrite(STDERR, 'setup.php-dist shape changed (' . substr($needle, 0, 40) . ")\n");
        exit(1);
    }
    $dist = str_replace($needle, $replacement, $dist);
}
$dist .= "\nif (!defined(\"__CA_LOCAL_CONFIG_DIRECTORY__\")) {\n\tdefine(\"__CA_LOCAL_CONFIG_DIRECTORY__\", __DIR__ . \"/app/conf/local\");\n}\n";
if (file_put_contents(__DIR__ . '/setup.php', $dist) === false) {
    fwrite(STDERR, "could not write setup.php\n");
    exit(1);
}
// 2. Stock CLI install (default profile). DB/user pre-exist (panel), so no
// root path is needed. Secrets ride argv (no shell — proc_open array form).
$cmd = [PHP_BINARY, '-d', 'memory_limit=1024M', 'support/bin/caUtils', 'install', '--profile-name=default', '--admin-email=' . $email];
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = ['COLLECTIVEACCESS_HOME' => __DIR__, 'PATH' => '/usr/bin:/bin'];
$proc = proc_open($cmd, $desc, $pipes, __DIR__, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "could not start installer\n");
    exit(1);
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
if ($out !== '') {
    echo $out;
}
if ($err !== '') {
    fwrite(STDERR, $err);
}
if ($code !== 0) {
    fwrite(STDERR, 'installer failed (exit ' . $code . ")\n");
    exit(1);
}
// 3. Rename the seeded administrator to the wizard username + set its
// password through the model (proper hashing).
require __DIR__ . '/setup.php';
require_once(__CA_APP_DIR__ . '/helpers/configurationHelpers.php');
$t = new ca_users();
if (!$t->load(['user_name' => 'administrator'])) {
    fwrite(STDERR, "seeded administrator missing\n");
    exit(1);
}
$t->set('user_name', $user);
$t->set('password', $pass);
$t->update();
if ($t->numErrors()) {
    fwrite(STDERR, join('; ', $t->getErrors()) . "\n");
    exit(1);
}
echo "providence setup complete\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}']],
                ],
                'cron' => [
                    ['schedule' => '* * * * *', 'command' => '{phpbin} -f {dest}/support/bin/caUtils process-task-queue'],
                ],
                'cleanup' => ['panel-setup.php', 'install'],
            ],
            'admin' => ['path' => ''],
            'post_install' => 'Installed automatically with the admin account you entered — log in from the front page (username, not email).',
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
