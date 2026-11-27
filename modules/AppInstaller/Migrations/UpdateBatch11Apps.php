<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 11 unattended setups (finance pair). InvoicePlane (first-party
 * CodeIgniter CLI controller: migrate builds the tables, create_default_user
 * reads env creds — the panel-setup.php driver sets them in-process and
 * re-dispatches index.php per step since the agent passes argv, never env)
 * and Invoice Ninja (migrate + storage link + ninja:create-account with
 * email/password options, scheduler via panel cron).
 */
class UpdateBatch11Apps extends Migration {
    public function up(): void
    {
        $this->merge('invoiceplane', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'mbstring', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => 'ip_'],
            'config' => [],
            'setup' => [
                'files' => ['ipconfig.php' => "IP_URL={url}\nENABLE_DEBUG=false\nCI_ENV=production\nSETUP_COMPLETED=true\nDB_HOSTNAME={db_host}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nDB_DATABASE={db_name}\nDB_PORT=3306\n",
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 5, '');
$mode = $args[1];
$email = trim($args[2]);
$name = trim($args[3]);
$pass = $args[4];
if (!in_array($mode, array('migrate', 'createuser'), true)
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $name === '' || strlen($pass) < 8
) {
    fwrite(STDERR, "usage: panel-setup.php <migrate|createuser> <email> <name> <password>\n");
    exit(1);
}
putenv('DEFAULT_ADMIN_EMAIL=' . $email);
putenv('DEFAULT_ADMIN_NAME=' . $name);
putenv('DEFAULT_ADMIN_PASSWORD=' . $pass);
$_SERVER['argv'] = array(
    'index.php',
    $mode === 'migrate' ? 'setup/cli/migrate' : 'setup/cli/create_default_user',
);
chdir(__DIR__);
require __DIR__ . '/index.php';
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'panel-setup.php', 'args' => ['migrate', '{email}', '{user}', '{pass}']],
                    ['script' => 'panel-setup.php', 'args' => ['createuser', '{email}', '{user}', '{pass}']],
                ],
                'cleanup' => ['application/modules/setup', 'panel-setup.php'],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL.',
        ]);

        $this->merge('invoiceninja', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.2', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'bcmath']],
            'root_only' => true,
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['.env' => "APP_NAME=\"Invoice Ninja\"\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={app_key}\nAPP_URL={url}\nREQUIRE_HTTPS=true\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nMAIL_MAILER=log\nCACHE_DRIVER=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\n"],
                'cli' => [
                    ['script' => 'artisan', 'args' => ['storage:link']],
                    ['script' => 'artisan', 'args' => ['migrate', '--seed', '--force']],
                    ['script' => 'artisan', 'args' => ['ninja:create-account', '--email={email}', '--password={pass}']],
                ],
                'cron' => [
                    ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/artisan schedule:run'],
                ],
            ],
            'webroot' => 'public',
            'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. The scheduler runs every minute via cron.',
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
