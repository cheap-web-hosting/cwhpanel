<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Batch 49: unattended first-run setup for two more runtime apps.
 *
 *  - gokapi     → POST /setup/setupResult (its /setup wizard payload: a JSON
 *                 array of {name,value}) writes config.json + DB + admin, then
 *                 the process continues into the normal server.
 *  - verdaccio  → PUT /-/user/org.couchdb.user:<user> creates the first
 *                 account (bcrypt htpasswd) from a work-dir config.yaml that
 *                 keeps storage + htpasswd inside the app work dir.
 *
 * Catalog seeding is insert-if-missing, so the live rows need this explicit
 * manifest convergence (the seed carries it to fresh installs).
 */
class UpdateBatch49AutoSetup extends Migration {

    private const SLUGS = ['gokapi', 'verdaccio'];

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