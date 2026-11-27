<?php

declare(strict_types=1);

namespace Modules\AppInstaller\Migrations;

use App\Foundation\Database\Migration;

/**
 * Converge two live catalog rows to the canonical seed manifests (seeding is
 * insert-if-missing, so existing rows never pick up seed repairs):
 *
 *  - opencart: PHP pinned to exactly 8.1 (3.0.5.x CLI installer range; the
 *    panel's stock 8.4/8.5 interpreter aborts the install). The pin was
 *    written into ownCloud's manifest by mistake in 1aa30e9.
 *  - lychee: `webroot` = `public` was lost from the live row (Laravel serves
 *    only public/; without it nginx points at the app root and every request
 *    404s).
 */
class UpdateOpencartLycheeManifests extends Migration
{
    public function up(): void
    {
        foreach (['opencart', 'lychee'] as $slug) {
            try {
                $entry = null;
                foreach (\Modules\AppInstaller\Seed\CatalogApps::all() as $app) {
                    if (($app['slug'] ?? '') === $slug) {
                        $entry = $app;
                        break;
                    }
                }
                if ($entry === null || !isset($entry['manifest'])) {
                    continue;
                }
                $row = $this->db->selectOne('SELECT `id` FROM `ai_applications` WHERE `slug` = ?', [$slug]);
                if (!$row) {
                    continue;
                }
                $this->db->update('ai_applications', [
                    'manifest' => json_encode($entry['manifest']),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [(int) $row['id']]);
            } catch (\Throwable $e) {
                // Best-effort convergence; the seed remains authoritative.
            }
        }
    }
}