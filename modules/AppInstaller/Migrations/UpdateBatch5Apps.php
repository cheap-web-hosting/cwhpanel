<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 5 unattended setups. Piwigo: web installer driven non-interactively
 * by a root-owned helper (panel-managed DB + admin account).
 */
class UpdateBatch5Apps extends Migration {
    public function up(): void
    {
        $this->merge('piwigo', [
            'type' => 'php-mysql',
            'requirements' => ['php_min' => '7.4', 'extensions' => ['mysqli', 'curl', 'gd', 'mbstring', 'xml', 'exif', 'intl']],
            'database' => ['required' => true, 'prefix' => 'piwigo_'],
            'config' => ['template' => 'piwigo'],
            'admin' => ['path' => 'admin.php'],
            'post_install' => 'Installed automatically with the admin account you entered — log in at /admin.php.',
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
