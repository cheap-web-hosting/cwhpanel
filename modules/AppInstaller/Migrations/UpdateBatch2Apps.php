<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 2 unattended setups. OpenCart: strip the upload/ wrapper, import
 * the shipped schema, generate store+admin config, insert the admin user.
 */
class UpdateBatch2Apps extends Migration {
    public function up(): void
    {
        $this->merge('opencart', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'php_max' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl']],
            'database' => ['required' => true, 'prefix' => 'oc_'],
            'download' => ['github' => ['repo' => 'opencart/opencart', 'asset' => 'opencart-{version}.zip', 'tag_prefix' => ''], 'sha256' => '', 'format' => 'zip', 'strip_root' => true, 'strip_dir' => 'upload'],
            'config' => [],
            'setup' => ['cli' => [
                ['script' => 'install/cli_install.php', 'args' => ['install', '--db_driver', 'mysqli', '--db_hostname', '{db_host}', '--db_port', '3306', '--db_username', '{db_user}', '--db_password', '{db_pass}', '--db_database', '{db_name}', '--db_prefix', '{prefix}', '--username', '{user}', '--password', '{pass}', '--email', '{email}', '--http_server', '{url}/']],
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
