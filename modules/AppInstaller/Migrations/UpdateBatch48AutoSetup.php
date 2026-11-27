<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 48: unattended first-run setup for four runtime apps that previously
 * greeted the owner with a browser wizard / first-user form. Each now ships a
 * `setup.cli` driver (seed_files panel-setup.php) that runs post-start and
 * mints the wizard admin:
 *
 *  - opengist   → `admin create-user` CLI (password via stdin)
 *  - navidrome  → POST /auth/createAdmin (its CLI prompts on a TTY)
 *  - statping-ng→ POST /api/setup (boots in setup mode until posted)
 *
 * (filebrowser is intentionally NOT here: it already works with the default
 * admin/admin and has no wizard, and its BoltDB is file-locked by the
 * running server so the CLI cannot re-point it — no automation gap.)
 *
 * Catalog seeding is insert-if-missing, so the live rows need this explicit
 * manifest convergence (the seed carries it to fresh installs).
 */
class UpdateBatch48AutoSetup extends Migration {

    private const SLUGS = ['opengist', 'navidrome', 'statping-ng'];

    public function up(): void
    {
        try {
            $seed = [];
            foreach (\Modules\AppInstaller\Seed\CatalogApps::all() as $a) {
                $slug = (string) ($a['slug'] ?? '');
                if ($slug !== '' && isset($a['manifest'])) {
                    $seed[$slug] = $a['manifest'];
                }
            }
            foreach (self::SLUGS as $slug) {
                if (!isset($seed[$slug])) {
                    continue;
                }
                $row = $this->db->selectOne('SELECT `id` FROM `ai_applications` WHERE `slug` = ?', [$slug]);
                if (!$row) {
                    continue;
                }
                $this->db->update('ai_applications', [
                    'manifest' => json_encode($seed[$slug]),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [(int) $row['id']]);
            }
        } catch (\Throwable $e) {
        }
    }
}