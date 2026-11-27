<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * FreshRSS needed two manifest repairs the live catalog row never got
 * (catalog seeding is insert-if-missing, so existing rows are immutable):
 *
 *  1. webroot `p` — FreshRSS's public UI lives in `p/` (root index.php
 *     hardcodes `Location: p/`, the real front controller is p/i/index.php,
 *     and FreshRSS builds every redirect as `<base_url>/i/...`). Without
 *     webroot, nginx served the install root, so `/mount/i/` fell through
 *     the site's try_files to the root index.php instead of the app.
 *  2. the setup driver takes discrete argv (the `{json}` placeholder it was
 *     written against does not exist in InstallerService, so do-install.php
 *     never ran — the browser wizard greeted every visitor).
 *
 * Converges the row to the canonical Seed\CatalogApps manifest (zero drift).
 */
class UpdateFreshRssManifest extends Migration {

    public function up(): void
    {
        try {
            $entry = null;
            foreach (\Modules\AppInstaller\Seed\CatalogApps::all() as $a) {
                if (($a['slug'] ?? '') === 'freshrss') {
                    $entry = $a;
                    break;
                }
            }
            if ($entry === null || !isset($entry['manifest'])) {
                return;
            }
            $row = $this->db->selectOne('SELECT `id` FROM `ai_applications` WHERE `slug` = ?', ['freshrss']);
            if (!$row) {
                return;
            }
            $this->db->update('ai_applications', [
                'manifest' => json_encode($entry['manifest']),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [(int) $row['id']]);
        } catch (\Throwable $e) {
        }
    }
}