<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 43 node sweep fixes (docsify bind host + vite allowed hosts).
 * docsify serve defaults to host "localhost", which node resolves to
 * ::1 while the proxy dials 127.0.0.1 — 502 with a healthy unit. Pin
 * the bind to 127.0.0.1. Vite 403s proxied Host headers (host check);
 * seed a config allowing them (the front door is the public face).
 * Existing installs keep their written launcher/seeds (reinstall to
 * pick these up).
 */
class UpdateBatch43DocsifyVite extends Migration {
    public function up(): void
    {
        $this->mergeNpmArgs('docsify', ['serve', '.', '--host', '127.0.0.1', '--port', '{PORT}']);
        $this->mergeSeed('vite', 'vite.config.js', "export default { server: { allowedHosts: true } };\n");
    }

    private function load(string $slug): ?array
    {
        try {
            $row = $this->db->selectOne('SELECT * FROM `ai_applications` WHERE `slug` = ?', [$slug]);
        } catch (\Throwable $e) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $manifest = json_decode((string) ($row['manifest'] ?? '{}'), true);
        return is_array($manifest) ? [$row, $manifest] : null;
    }

    private function save(string $slug, array $manifest): void
    {
        try {
            $this->db->update('ai_applications', [
                'manifest' => json_encode($manifest),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`slug` = ?', [$slug]);
        } catch (\Throwable $e) {
        }
    }

    private function mergeNpmArgs(string $slug, array $args): void
    {
        $loaded = $this->load($slug);
        if ($loaded === null) {
            return;
        }
        [, $manifest] = $loaded;
        $manifest['npm']['args'] = $args;
        $this->save($slug, $manifest);
    }

    private function mergeSeed(string $slug, string $file, string $content): void
    {
        $loaded = $this->load($slug);
        if ($loaded === null) {
            return;
        }
        [, $manifest] = $loaded;
        $seeds = $manifest['app']['seed_files'] ?? [];
        if (!is_array($seeds)) {
            $seeds = [];
        }
        $seeds[$file] = $content;
        $manifest['app']['seed_files'] = $seeds;
        $this->save($slug, $manifest);
    }
}
