<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 34 unattended setup (Dolphin solo).
 * The stock install/cmd.php is a real CLI installer with clean exit codes,
 * driven by --opt=value argv — but install/index.php uses CWD-relative
 * includes while the agent runs without cd, so the driver chdirs into
 * install/ and execs the same interpreter (PHP_BINARY). Two stock quirks
 * handled: +x on flash/.../ffmpeg.exe (ships 0644) and a stale eval() of
 * the sql_file Closure (patched to a call, guarded). PHP 7.4-only with
 * allow_url_include Off (new managed PHP option, rides the auto-pin via
 * manifest php_settings). Proven live on a hostddl.net subdir: install,
 * front 200, member + admin login, full uninstall.
 */
class UpdateBatch34Apps extends Migration {
    public function up(): void
    {
        $this->merge('dolphin', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '7.4', 'php_max' => '7.99', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'php_settings' => ['allow_url_include' => '0'],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
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
$url = rtrim(trim($args[9]), '/') . '/';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
    || $dbname === '' || $dbuser === '' || $dbpass === ''
    || !preg_match('#^https?://#', $url)
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass> <dbhost> <dbname> <dbuser> <dbpass> <url>\n");
    exit(1);
}
// Stock CLI has no host option (localhost default); the panel DB user
// carries a localhost grant, so refuse anything else loudly.
if ($dbhost !== 'localhost' && $dbhost !== '127.0.0.1') {
    fwrite(STDERR, "dolphin installs against the local database only\n");
    exit(1);
}
// The stock permissions gate demands +x on flash/.../ffmpeg.exe, but the
// zip ships it 0644. Mirror the documented install chmod here (the
// account owns the tree, so this is self-service, never privileged).
@chmod(__DIR__ . '/flash/modules/global/app/ffmpeg.exe', 0755);
$installDir = __DIR__ . '/install';
// Upstream cmd.php is stale: _setSiteConfigVars() eval()s the sql_file
// def_exp, but it has been a Closure for years (the web flow invokes it).
// Patch the single line to call it (guarded: fail loudly if the line ever
// changes shape instead of running a broken installer).
$cmdPhp = file_get_contents($installDir . '/cmd.php');
$needle = 'eval($GLOBALS[\'aDbConf\'][\'sql_file\'][\'def_exp\'])';
if (strpos($cmdPhp, $needle) === false) {
    fwrite(STDERR, "cmd.php patch point missing\n");
    exit(1);
}
$cmdPhp = str_replace($needle, 'call_user_func($GLOBALS[\'aDbConf\'][\'sql_file\'][\'def_exp\'])', $cmdPhp);
file_put_contents($installDir . '/cmd.php', $cmdPhp);
if (!is_file($installDir . '/cmd.php')) {
    fwrite(STDERR, "install/cmd.php missing\n");
    exit(1);
}
$opts = [
    '--site_url=' . $url,
    '--site_dir=' . __DIR__ . '/',
    '--php_path=' . PHP_BINARY,
    '--db_name=' . $dbname,
    '--db_user=' . $dbuser,
    '--db_password=' . $dbpass,
    '--site_title=' . $title,
    '--site_email=' . $email,
    '--admin_email=' . $email,
    '--admin_username=' . $user,
    '--admin_password=' . $pass,
];
$cmd = array_merge([PHP_BINARY, '-d', 'memory_limit=1024M', 'cmd.php'], $opts);
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $desc, $pipes, $installDir);
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
exit(is_int($code) ? $code : 1);
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}', '{db_host}', '{db_name}', '{db_user}', '{db_pass}', '{url}'], 'php_version' => '74'],
                ],
                'cleanup' => ['panel-setup.php', 'install'],
            ],
            'admin' => ['path' => 'administration/'],
            'post_install' => 'Installed automatically with the admin account you entered — sign in on the front page or at /administration/. Root installs on PHP 7.4 need allow_url_include Off (Websites → PHP Settings).',
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
