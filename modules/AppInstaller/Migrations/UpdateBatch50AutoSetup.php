<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 50: unattended first-run for the remaining runtime apps.
 *
 *  - notebook  → seeded `jupyter_server_config.py` (JUPYTER_CONFIG_DIR=.) sets
 *                the access token to the wizard password; `setup.requires_creds`
 *                makes the form collect it ( {pass} seed interpolation).
*  - radicale  → seeded htpasswd `users` (sha256-crypt via {pass_sha256}) +
 *                `config` (auth htpasswd/sha256, no extra Python deps).
 *
 * (shiori and filebrowser were removed from the catalog instead — neither can
 * be set up unattended and neither has a usable login flow; see
 * RemoveUnautomatableApps.)
 */
class UpdateBatch50AutoSetup extends Migration {

    private const SLUGS = ['notebook', 'radicale'];

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