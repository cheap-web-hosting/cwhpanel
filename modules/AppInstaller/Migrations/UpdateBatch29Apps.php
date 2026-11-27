<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 29 unattended setup (Group-Office solo).
 * No CLI installer ships: the driver mirrors install/install.php's POST
 * handler headless (stock core Installer::install + legacy module
 * auto-installs + legacy cron rows + admin legacyOnSave). Proven live on a
 * hostddl.net subdir: install, front 200, browser login to the desktop,
 * full uninstall.
 */
class UpdateBatch29Apps extends Migration {
    public function up(): void
    {
        $this->merge('groupoffice', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['pdo_mysql', 'curl', 'gd', 'mbstring', 'xml', 'dom', 'fileinfo', 'intl', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['config.php' => <<<'PANELCONFIG'
<?php

$config = array(
  'file_storage_path' => '{home}/groupoffice-{dir}',
  'tmpdir' => '{home}/groupoffice-{dir}-tmp',
  'db_host' => '{db_host}',
  'db_name' => '{db_name}',
  'db_user' => '{db_user}',
  'db_pass' => '{db_pass}',
  'db_port' => 3306,
);
PANELCONFIG,
                    'panel-setup.php' => <<<'PANELSETUP'
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
    || !preg_match('/^[A-Za-z0-9_.\-@]{1,32}$/', $user) || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <title> <email> <user> <pass>\n");
    exit(1);
}
// The agent runs setup scripts without cd-ing into the tree.
chdir(__DIR__);
if (!is_file(__DIR__ . '/config.php')) {
    fwrite(STDERR, "config.php missing\n");
    exit(1);
}
$config = [];
require __DIR__ . '/config.php';
foreach (['file_storage_path', 'tmpdir'] as $k) {
    if (!empty($config[$k]) && !is_dir($config[$k])) {
        mkdir($config[$k], 0755, true);
    }
}
require __DIR__ . '/vendor/autoload.php';
try {
    go\core\App::get()->setAuthState(new go\core\auth\TemporaryState());
    $admin = [
        'displayName' => 'System Administrator',
        'username' => $user,
        'password' => $pass,
        'email' => $email,
        'language' => 'en',
    ];
    go\core\App::get()->getInstaller()->install($admin);
    // Legacy (not yet refactored) modules, as install.php does.
    GO::$ignoreAclPermissions = true;
    foreach (GO::modules()->getAvailableModules() as $moduleClass) {
        $moduleController = $moduleClass::get();
        if ($moduleController instanceof go\core\Module) {
            continue;
        }
        if ($moduleController->autoInstall() && $moduleController->isInstallable()) {
            try {
                GO\Base\Model\Module::install($moduleController->name(), false, $moduleController::getDefaultSortOrder());
            } catch (Throwable $e) {
                go\core\ErrorHandler::logException($e);
            }
        }
    }
    foreach ([
        ['Email Reminders', '*/5', '*', 'GO\Base\Cron\EmailReminders'],
        ['Calculate disk usage', '1', '1', 'GO\Base\Cron\CalculateDiskUsage'],
    ] as [$name, $minutes, $hours, $job]) {
        $cron = new GO\Base\Cron\CronJob();
        $cron->name = $name;
        $cron->active = true;
        $cron->runonce = false;
        $cron->minutes = $minutes;
        $cron->hours = $hours;
        $cron->monthdays = '*';
        $cron->months = '*';
        $cron->weekdays = '*';
        $cron->job = $job;
        if (!$cron->save()) {
            throw new RuntimeException('Could not save cron ' . $name);
        }
    }
    GO\Base\Observable::cacheListeners();
    go\core\model\User::findById(1)->legacyOnSave();
    echo "groupoffice setup complete\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['{title}', '{email}', '{user}', '{pass}']],
                ],
                'datadirs' => ['groupoffice-{dir}', 'groupoffice-{dir}-tmp'],
                'cron' => [
                    ['schedule' => '* * * * *', 'command' => '{phpbin} -f {dest}/cron.php {dest}/config.php'],
                ],
                'cleanup' => ['panel-setup.php', 'install'],
            ],
            'admin' => ['path' => ''],
            'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. If the first login asks for a new password, yours appeared in a breach list (Group-Office checks HaveIBeenPwned); the scheduler runs every minute via cron.',
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
