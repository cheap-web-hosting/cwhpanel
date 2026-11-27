<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 9 unattended setups. Firefly III (migrate + passport + upgrade
 * corrections, owner user + group membership via a one-shot self-deleting
 * panel-setup.php — Firefly ships no create-user CLI) and Koel
 * (koel:init --no-interaction, default admin re-credentialed the same way;
 * scheduler via panel cron). Both need PHP >= 8.3 (Laravel 12).
 */
class UpdateBatch9Apps extends Migration {
    public function up(): void
    {
        $this->merge('firefly', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.3', 'extensions' => ['mysqli', 'curl', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'bcmath', 'sodium']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['.env' => "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={app_key}\nAPP_URL={url}\nSITE_OWNER={email}\nTRUSTED_PROXIES=*\nTZ=UTC\nLOG_CHANNEL=stack\nCACHE_DRIVER=file\nSESSION_DRIVER=file\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_SOCKET=\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nMAIL_MAILER=log\nMAIL_FROM={email}\n",
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 3, '');
$email = $args[1];
$pass = $args[2];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
    fwrite(STDERR, "usage: panel-setup.php <email> <password>\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!FireflyIII\User::where('email', $email)->exists()) {
    $user = FireflyIII\User::create(['email' => $email, 'password' => Illuminate\Support\Facades\Hash::make($pass)]);
    $group = FireflyIII\Models\UserGroup::create(['title' => $email]);
    $role = FireflyIII\Models\UserRole::where('title', 'owner')->firstOrFail();
    FireflyIII\Models\GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);
    $user->user_group_id = $group->id;
    $user->save();
    echo "owner ready\n";
} else {
    echo "owner exists\n";
}
@unlink(__FILE__);
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'artisan', 'args' => ['migrate', '--seed', '--force']],
                    ['script' => 'artisan', 'args' => ['passport:keys', '--force']],
                    ['script' => 'artisan', 'args' => ['passport:client', '--personal', '--name=Firefly III Personal Access Client']],
                    ['script' => 'artisan', 'args' => ['passport:client', '--password', '--name=Firefly III Password Grant Client']],
                    ['script' => 'artisan', 'args' => ['firefly-iii:upgrade-database']],
                    ['script' => 'panel-setup.php', 'args' => ['{email}', '{pass}']],
                ],
                'cron' => [
                    ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/artisan firefly-iii:cron'],
                ],
            ],
            'webroot' => 'public',
            'post_install' => 'Installed automatically with the admin account you entered — log in with your email address at the site URL. Recurring-task cron is enabled automatically.',
        ]);

        $this->merge('koel', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.3', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['.env' => "APP_NAME=Koel\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={app_key}\nAPP_URL={url}\nTRUSTED_HOSTS={domain}\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nMEDIA_PATH=\nMAIL_MAILER=log\nCACHE_DRIVER=file\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\nFFMPEG_PATH=/tmp/no-ffmpeg\nTRANSCODE_FLAC=false\n",
                    'panel-setup.php' => <<<'PANELSETUP'
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_pad($argv, 4, '');
$name = trim($args[1]);
$email = $args[2];
$pass = $args[3];
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
    fwrite(STDERR, "usage: panel-setup.php <name> <email> <password>\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$target = App\Models\User::where('email', $email)->first();
if ($target === null) {
    $target = App\Models\User::where('email', App\Models\User::FIRST_ADMIN_EMAIL)->firstOrFail();
}
$target->name = $name;
$target->email = $email;
$target->password = Illuminate\Support\Facades\Hash::make($pass);
$target->save();
echo "admin ready\n";
@unlink(__FILE__);
PANELSETUP,
                ],
                'cli' => [
                    ['script' => 'artisan', 'args' => ['koel:init', '--no-assets', '--no-interaction', '--no-scheduler']],
                    ['script' => 'panel-setup.php', 'args' => ['{user}', '{email}', '{pass}']],
                    // Bake the config: Koel resolves ffmpeg via shell_exec()
                    // at boot and the PHP masters disable it — the cached
                    // config skips the call.
                    ['script' => 'artisan', 'args' => ['config:cache']],
                ],
                'cron' => [
                    ['schedule' => '* * * * *', 'command' => '{phpbin} {dest}/artisan schedule:run'],
                ],
                // Koel's REST API uses PUT/PATCH/DELETE, which CRS
                // method enforcement (911100) blocks by default.
                'waf' => ['exclude' => [911100]],
            ],
            'webroot' => 'public',
            'post_install' => 'Installed automatically with the admin account you entered — log in at the site URL. The Laravel scheduler runs every minute via cron.',
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
