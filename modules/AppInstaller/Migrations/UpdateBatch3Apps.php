<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 3 unattended setups.
 *  - Lychee: Laravel app — generated .env + artisan migrate + create user.
 *  - PrestaShop: wrapper zip (nested prestashop.zip) + upstream index_cli.
 */
class UpdateBatch3Apps extends Migration {
    public function up(): void
    {
        $this->merge('lychee', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.4', 'extensions' => ['mysqli', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'bcmath']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['.env' => "APP_NAME=Lychee\nAPP_ENV=production\nAPP_KEY={app_key}\nAPP_DEBUG=false\nAPP_URL={url}\nAPP_TIMEZONE=UTC\nLOG_CHANNEL=stack\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nSESSION_DRIVER=file\nQUEUE_CONNECTION=sync\nCACHE_DRIVER=file\n"],
                'cli' => [
                    ['script' => 'artisan', 'args' => ['migrate', '--force']],
                    ['script' => 'artisan', 'args' => ['lychee:create_user', '{user}', '{pass}', '--may-administrate']],
                ],
            ],
            'webroot' => 'public',
            'post_install' => 'Installed automatically with the admin account you entered — just log in at the site URL.',
        ]);

        $this->merge('prestashop', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl']],
            'database' => ['required' => true, 'prefix' => 'ps_'],
            'download' => ['github' => ['repo' => 'PrestaShop/PrestaShop', 'asset' => 'prestashop_{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'nested' => 'prestashop.zip'],
            'config' => [],
            'setup' => ['cli' => [
                ['script' => 'install/index_cli.php', 'args' => ['--domain={domain}', '--base_uri={scriptpath}', '--db_server={db_host}', '--db_user={db_user}', '--db_password={db_pass}', '--db_name={db_name}', '--db_clear=0', '--db_create=0', '--prefix={prefix}', '--name={title}', '--firstname=Admin', '--lastname=Admin', '--password={pass}', '--email={email}', '--country=us', '--language=en']],
            ], 'cleanup' => ['install']],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /admin/.',
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
