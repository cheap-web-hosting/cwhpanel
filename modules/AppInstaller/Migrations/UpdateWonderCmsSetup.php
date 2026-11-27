<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * WonderCMS ships an EMPTY data/database.js. The setup driver's getDb() saw
 * an existing file, skipped createDb(), json_decode('') returned null and
 * every request died:
 *
 *   TypeError: Wcms::getDb(): Return value must be of type stdClass, null returned
 *
 * (HTTP 500 after a "completed" install). The driver now deletes the empty
 * placeholder before booting the app. Catalog seeding is insert-if-missing,
 * so converge the live row to the canonical Seed\CatalogApps manifest.
 */
class UpdateWonderCmsSetup extends Migration {

    public function up(): void
    {
        try {
            $entry = null;
            foreach (\Modules\AppInstaller\Seed\CatalogApps::all() as $a) {
                if (($a['slug'] ?? '') === 'wondercms') {
                    $entry = $a;
                    break;
                }
            }
            if ($entry === null || !isset($entry['manifest'])) {
                return;
            }
            $row = $this->db->selectOne('SELECT `id` FROM `ai_applications` WHERE `slug` = ?', ['wondercms']);
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