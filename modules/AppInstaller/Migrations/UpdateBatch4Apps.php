<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 4 unattended setups. Joomla: configuration.php (JConfig class) +
 * base.sql/extensions.sql import + admin user & super-user group mapping.
 */
class UpdateBatch4Apps extends Migration {
    public function up(): void
    {
        $this->merge('joomla', [
            'type' => 'php-mysql',
            'database' => ['required' => true, 'prefix' => 'jos_'],
            'config' => ['template' => 'joomla', 'file' => 'configuration.php', 'prefix' => 'jos_'],
            'setup' => ['sql' => [
                'schemas' => ['installation/sql/mysql/base.sql', 'installation/sql/mysql/extensions.sql', 'installation/sql/mysql/supports.sql'],
                'prefix_from' => '#__',
                'admin' => ['table' => 'users', 'user_col' => 'username', 'pass_col' => 'password', 'email_col' => 'email', 'hash' => 'bcrypt', 'extra' => ['id' => '1', 'name' => 'Administrator', 'sendEmail' => '1', 'registerDate' => '{date}', 'params' => '{"admin_style":"","admin_language":"","language":"","editor":"","timezone":""}']],
                'related' => [['table' => 'user_usergroup_map', 'cols' => ['user_id' => '{admin_id}', 'group_id' => '8']]],
                'cleanup' => ['installation'],
            ]],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /administrator/.',
        ]);

        $this->merge('drupal', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.3', 'db_min' => '10.6', 'extensions' => ['gd', 'curl', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'pdo_mysql']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => ['template' => 'drupal'],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /user/login.',
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
