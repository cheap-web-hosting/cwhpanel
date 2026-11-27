<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 21 unattended setup (LiveHelperChat solo).
 * Two repairs in one: the asset nests the app under lhc_web/ (fixed via
 * strip_dir — previously every install landed a level deep and served
 * nothing), and setup runs upstream's Install class steps directly
 * (install-cli.php's main() exits 1 even on success and needs CWD, so a
 * driver with clean exit codes drives step1-step4 instead) fed by a
 * generated settings INI.
 */
class UpdateBatch21Apps extends Migration {
    public function up(): void
    {
        $this->merge('livehelperchat', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => ''],
            'download' => ['github' => ['repo' => 'LiveHelperChat/livehelperchat', 'asset' => '{tag}-with-dependencies.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'strip_dir' => 'lhc_web'],
            'config' => [],
            'setup' => [
                'files' => [
                    'cli/panel-settings.ini' => <<<'LHINI'
[db]
host = {db_host}
user = {db_user}
password = {db_pass}
database = {db_name}
port = 3306

[admin]
AdminUsername = {user}
AdminPassword = {pass}
AdminEmail = {email}
AdminName = Admin
AdminSurname = Admin
Domain = {domain}
DefaultDepartament = Support
ForceVirtualHost = 0
Extensions =
ApacheUserGroupName = apache
ApacheUserName = apache
TimeZone = UTC
WebhooksEnabled = 0
WebhooksWorker = http
LHINI,
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 2, '');
$ini = trim($args[1] ?? '');
if ($ini === '' || !is_file($ini)) {
    fwrite(STDERR, "usage: panel-setup.php <settings.ini>\n");
    exit(1);
}
chdir(__DIR__);
require_once "lib/core/lhcore/password.php";
require_once "ezcomponents/Base/src/base.php";
require_once 'cli/lib/install.php';
ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');
$install = new Install($ini);
foreach (['step1', 'step2', 'step3', 'step4'] as $i => $step) {
    $r = $install->$step();
    if (is_array($r)) {
        fwrite(STDERR, 'step' . ($i + 1) . ': ' . implode('; ', $r) . "\n");
        exit(1);
    }
    echo 'step' . ($i + 1) . " ok\n";
}
echo "installed\n";
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{dest}/cli/panel-settings.ini']],
                ],
                'cleanup' => ['cli/panel-settings.ini', 'panel-setup.php'],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in under Site administration.',
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
