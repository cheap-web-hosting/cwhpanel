<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 44 python sweep (remove Isso/OctoPrint/ESPHome + Radicale/WsgiDAV fixes).
 * - Isso is uninstallable by this panel: its Markdown dependency misaka
 *   ships no wheels for this platform and needs compilation (compilers
 *   are root-only by design), so every isso version fails dependency
 *   resolution under the wheel-only policy. Removed like Arastta/Grocy.
 * - OctoPrint needs netifaces (C extension, no wheels here) — same fate.
 * - ESPHome pins paho-mqtt==1.6.1, which is sdist-only — same fate.
 * - Radicale defaults its collection root to the unwritable
 *   /var/lib/radicale — point it at an account-owned collections/ dir.
 * - WsgiDAV defaults to the cheroot server backend, which is not
 *   installed (and is not a dependency) — use stdlib wsgiref instead.
 * Existing installs keep their written launchers/seeds (reinstall to
 * pick the fixes up).
 */
class UpdateBatch44PythonSweep extends Migration {
    public function up(): void
    {
        foreach (['isso', 'octoprint', 'esphome'] as $slug) {
            try {
                $this->db->delete('ai_applications', '`slug` = ?', [$slug]);
            } catch (\Throwable $e) {
            }
        }
        $this->merge('radicale', [
            'pip' => ['package' => 'radicale', 'script' => 'radicale', 'args' => ['--hosts', '{HOST}:{PORT}', '--storage-filesystem-folder', 'collections']],
            'app' => ['seed_files' => [
                'collections/README.txt' => "CalDAV/CardDAV collections live here.\n",
            ]],
        ]);
        $this->merge('wsgidav', [
            'pip' => ['package' => 'wsgidav', 'script' => 'wsgidav', 'args' => ['--host={HOST}', '--port={PORT}', '--root=share', '--server', 'wsgiref']],
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
