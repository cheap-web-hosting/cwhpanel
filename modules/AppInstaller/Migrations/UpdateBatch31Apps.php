<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 31 unattended setup (Typesetter solo, no database).
 * PHP 7.4 ONLY: the tree uses curly-brace string offsets ($str{0}), a
 * compile fatal on PHP 8.0+, in core files — hence php_max plus a pinned
 * php_version CLI step. The driver calls the supported programmatic API
 * (also used by the Multi-Site addon): gpInstall_Check() validates,
 * Install_DataFiles_New() writes the data tree + the sha512 admin row
 * (base_install=false skips session creation). Proven live on a
 * hostddl.net subdir (auto-pin 74): install, front 200, evaluated-hash
 * verify, login POST into Administration, full uninstall.
 */
class UpdateBatch31Apps extends Migration {
    public function up(): void
    {
        $this->merge('typesetter', [
            'type' => 'php',
            'requirements' => ['php_min' => '7.4', 'php_max' => '7.99', 'extensions' => ['curl', 'mbstring', 'zip', 'json', 'openssl']],
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
$email = trim($args[2]);
$user = trim($args[3]);
$pass = $args[4] ?? '';
if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $user) || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass>\n");
    exit(1);
}
define('is_running', true);
// The agent runs setup scripts without cd-ing into the tree.
chdir(__DIR__);
global $dataDir;
$dataDir = __DIR__;
require __DIR__ . '/include/common.php';
spl_autoload_register(['\\gp\\tool', 'Autoload']);
\gp\tool::GetLangFile('main.inc', 'en');
$_POST = [
    'username' => $user,
    'password' => $pass,
    'password1' => $pass,
    'email' => $email,
    'site_title' => $title,
];
if (!\gp\install\Tools::gpInstall_Check()) {
    fwrite(STDERR, "install check failed (see log)\n");
    exit(1);
}
if (!\gp\install\Tools::Install_DataFiles_New(__DIR__, ['language' => 'en'], false)) {
    fwrite(STDERR, "data files install failed\n");
    exit(1);
}
echo "typesetter setup complete\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}'], 'php_version' => '74'],
                ],
                'cleanup' => ['panel-setup.php'],
            ],
            'admin' => ['path' => 'Admin'],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /Admin.',
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
