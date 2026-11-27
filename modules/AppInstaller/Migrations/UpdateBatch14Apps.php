<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 14 unattended setups. LinkStack (Laravel: .env + migrate +
 * AdminSeeder, then the seeded admin/admin@admin.com account is
 * re-credentialed to the wizard values via sql_post bcrypt update).
 *
 * NOTE: Microweber was dropped from this batch — microweber.com/download
 * serves an HTML page, not a zip, and upstream distributes only via
 * `composer create-project` (no release archives anywhere), which the
 * installer cannot consume. The catalog still carries its old manual
 * stub until a distribution appears.
 */
class UpdateBatch14Apps extends Migration {
    public function up(): void
    {
        $this->merge('linkstack', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '8.1', 'extensions' => ['mysqli', 'curl', 'mbstring', 'zip', 'json']],
            'database' => ['required' => true, 'prefix' => ''],
            'config' => [],
            'setup' => [
                'files' => ['.env' => "APP_NAME=LinkStack\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={app_key}\nAPP_URL={url}\nDB_CONNECTION=mysql\nDB_HOST={db_host}\nDB_PORT=3306\nDB_DATABASE={db_name}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\nCACHE_DRIVER=file\nQUEUE_CONNECTION=sync\nSESSION_DRIVER=file\nSESSION_LIFETIME=120\nREGISTER_AUTH=auth\n"],
                'cli' => [
                    ['script' => 'artisan', 'args' => ['migrate', '--force']],
                    ['script' => 'artisan', 'args' => ['db:seed', '--class=AdminSeeder', '--force']],
                ],
                'sql_post' => [
                    ['table' => 'users', 'set' => ['name' => '{user}', 'email' => '{email}', 'password' => '{pass_hash}'], 'where' => ['email' => 'admin@admin.com']],
                ],
            ],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /login.',
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
