<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 43 node sweep fix (Serve listen URI).
 * serve v14 parses `-l 127.0.0.1:port` as a scheme endpoint and refuses
 * it; host:port forms need the explicit `tcp://` scheme. Existing
 * installs keep their written launcher (reinstall to pick this up).
 */
class UpdateBatch43Serve extends Migration {
    public function up(): void
    {
        try {
            $row = $this->db->selectOne('SELECT * FROM `ai_applications` WHERE `slug` = ?', ['serve']);
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
        $manifest['npm']['args'] = ['-l', 'tcp://{HOST}:{PORT}', 'www'];
        try {
            $this->db->update('ai_applications', [
                'manifest' => json_encode($manifest),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`slug` = ?', ['serve']);
        } catch (\Throwable $e) {
        }
    }
}
