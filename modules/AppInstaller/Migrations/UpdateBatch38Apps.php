<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 38 unattended setup (OpenEMR solo).
 * No usable stock CLI entry (InstallerAuto.php reads key=value argv and
 * needs an env gate), so the driver constructs the stock Installer
 * directly with JSON argv and runs quick_install() in no_root_db_access
 * mode (schema + sqlconf + bcrypt admin + gacl). Runs as the account
 * (UID 0 refused by the tree). Type promoted php -> php-mysql (the app
 * needs the panel database), floor corrected to the real 8.3.
 */
class UpdateBatch38Apps extends Migration {
    public function up(): void
    {
        $this->merge('openemr', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.3', 'extensions' => ['pdo_mysql', 'mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'fileinfo', 'intl', 'ldap', 'soap', 'sodium', 'bcmath', 'imagick', 'calendar', 'ctype', 'iconv', 'tokenizer', 'sockets']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$in = json_decode($argv[1] ?? 'null', true);
if (!is_array($in)) {
    fwrite(STDERR, "openemr setup: bad arguments\n");
    exit(1);
}
foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'user', 'pass'] as $k) {
    if (!isset($in[$k]) || !is_string($in[$k]) || $in[$k] === '' || strlen($in[$k]) > 255) {
        fwrite(STDERR, "openemr setup: missing {$k}\n");
        exit(1);
    }
    if (str_contains($in[$k], "\n") || str_contains($in[$k], "\0")) {
        fwrite(STDERR, "openemr setup: bad {$k}\n");
        exit(1);
    }
}
chdir(__DIR__);
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/library/classes/Installer.class.php';
$settings = [
    'iuser' => $in['user'],
    'iuname' => 'Administrator',
    'iufname' => 'Site',
    'iuserpass' => $in['pass'],
    'igroup' => 'Default',
    'server' => $in['db_host'],
    'loginhost' => $in['db_host'],
    'port' => '3306',
    'login' => $in['db_user'],
    'pass' => $in['db_pass'],
    'dbname' => $in['db_name'],
    'collate' => 'utf8mb4_unicode_ci',
    'site' => 'default',
    'no_root_db_access' => '1',
];
$installer = new Installer($settings, OpenEMR\BC\ServiceContainer::getLogger());
if (!$installer->quick_install()) {
    fwrite(STDERR, 'openemr setup failed: ' . $installer->error_message . "\n");
    exit(1);
}
echo "openemr setup complete\n";
PANELSETUP,
                ],
                'cli' => [[
                    'script' => 'panel-setup.php',
                    'args' => ['{"db_host":"{db_host}","db_name":"{db_name}","db_user":"{db_user}","db_pass":"{db_pass}","user":"{user}","pass":"{pass}"}'],
                ]],
                'cleanup' => ['panel-setup.php', 'setup.php', 'contrib/util/installScripts/InstallerAuto.php'],
            ],
            'admin' => ['path' => 'interface/login/login.php'],
            'post_install' => 'Installed automatically with the admin account you entered — log in at Interface → Login.',
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