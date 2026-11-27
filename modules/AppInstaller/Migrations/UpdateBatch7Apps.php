<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 7 unattended setups. Mautic (bin/console mautic:install) and
 * Concrete CMS (concrete/bin/concrete c5:install) — panel-managed DB +
 * upstream CLI installer.
 */
class UpdateBatch7Apps extends Migration {
    public function up(): void
    {
        $this->merge('mautic', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'db_min' => '10.11', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'imap']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => ['cli' => [
                ['script' => 'bin/console', 'args' => ['mautic:install', '{url}', '--db_driver=pdo_mysql', '--db_host={db_host}', '--db_port=3306', '--db_name={db_name}', '--db_user={db_user}', '--db_password={db_pass}', '--db_table_prefix={prefix}', '--admin_firstname=Admin', '--admin_lastname=Admin', '--admin_username={user}', '--admin_email={email}', '--admin_password={pass}', '--force', '-n']],
            ]],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /s/login. Add the Mautic cron jobs from Settings → Configuration.',
        ]);

        $this->merge('concrete', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'json', 'openssl', 'intl', 'fileinfo']],
            'database' => ['required' => true, 'prefix' => 'Concrete'],
            'config' => [],
            'setup' => ['cli' => [
                ['script' => 'concrete/bin/concrete', 'args' => ['c5:install', '--db-server={db_host}', '--db-username={db_user}', '--db-password={db_pass}', '--db-database={db_name}', '--site={title}', '--canonical-url={url}/', '--admin-email={email}', '--admin-password={pass}', '--language=en_US', '--timezone=UTC', '--session-handler=file']],
            ]],
            'post_install' => 'Installed automatically — log in at /index.php/login with the admin email you entered (Concrete uses the email as the username).',
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
