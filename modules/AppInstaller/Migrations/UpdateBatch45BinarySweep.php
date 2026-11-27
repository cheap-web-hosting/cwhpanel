<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 45 binary sweep (remove Zola + Opengist config flag).
 * - Zola is uninstallable on Rocky 9: the release binary needs glibc
 *   >= 2.35, the host ships 2.34 (`libm.so.6: version GLIBC_2.35 not
 *   found`). OS-level, like Grocy's sqlite floor. Removed.
 * - Opengist ignores ./config.yml unless --config precedes the start
 *   command (it is a GLOBAL option): without it the unit binds the
 *   default port while the proxy dials the allocated one (active + 502).
 * Existing installs keep their written launcher (reinstall to pick the
 * flag up).
 */
class UpdateBatch45BinarySweep extends Migration {
    public function up(): void
    {
        try {
            $this->db->delete('ai_applications', '`slug` = ?', ['zola']);
        } catch (\Throwable $e) {
        }
        try {
            $row = $this->db->selectOne('SELECT * FROM `ai_applications` WHERE `slug` = ?', ['opengist']);
        } catch (\Throwable $e) {
            return;
        }
        if ($row === null) {
            return;
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        if (!is_array($manifest)) {
            return;
        }
        $manifest['app']['args'] = ['--config', 'config.yml', 'start'];
        try {
            $this->db->update('ai_applications', [
                'manifest' => json_encode($manifest),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`slug` = ?', ['opengist']);
        } catch (\Throwable $e) {
        }
    }
}
