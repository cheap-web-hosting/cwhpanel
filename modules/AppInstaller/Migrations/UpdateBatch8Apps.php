<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 8 unattended setups. Akaunting (artisan install CLI) and Homer
 * (starter config.yml so the dashboard works out of the box).
 */
class UpdateBatch8Apps extends Migration {
    public function up(): void
    {
        $this->merge('akaunting', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'bcmath']],
            'database' => ['required' => true, 'prefix' => 'ak_'],
            'config' => [],
            'setup' => ['cli' => [
                ['script' => 'artisan', 'args' => ['install', '--db-host={db_host}', '--db-port=3306', '--db-name={db_name}', '--db-username={db_user}', '--db-password={db_pass}', '--db-prefix={prefix}', '--company-name={title}', '--company-email={email}', '--admin-email={email}', '--admin-password={pass}']],
            ]],
            'post_install' => 'Installed automatically — log in with the admin email you entered.',
        ]);

        $this->merge('homer', [
            'config' => ['files' => ['assets/config.yml' => 'homer']],
            'post_install' => 'Ready with a starter dashboard — edit assets/config.yml in the File Manager to add your services.',
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
