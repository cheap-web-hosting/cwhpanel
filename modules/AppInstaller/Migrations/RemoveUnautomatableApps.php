<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Remove two runtime apps that cannot be set up unattended and have no
 * usable login flow (catalog 94 → 92):
 *
 *  - shiori: v1.8 dropped the `account` command and ships NO server-side
 *    auth at all (its web UI is open), so there is nothing to automate.
 *  - filebrowser: v2.63 bootstraps admin with a random password printed in
 *    the logs (its API login needs that unknown password, and its BoltDB is
 *    file-locked by the running server so the CLI cannot re-point it);
 *    upstream is archived.
 *
 * The catalog seed no longer contains them, so Module::boot()'s converge
 * deletion also removes them (a stale-code boot can resurrect rows). This
 * migration is the immediate, explicit cleanup. Guarded: a slug with a live
 * (non-uninstalled) installation is left alone.
 */
class RemoveUnautomatableApps extends Migration {

    private const SLUGS = ['shiori', 'filebrowser'];

    public function up(): void
    {
        foreach (self::SLUGS as $slug) {
            try {
                $live = $this->db->scalar(
                    "SELECT COUNT(*) FROM `ai_installations` WHERE `application_slug` = ? AND `status` <> 'uninstalled'",
                    [$slug]
                );
                if ((int) $live > 0) {
                    continue;
                }
                $this->db->delete('ai_applications', '`slug` = ?', [$slug]);
            } catch (\Throwable $e) {
            }
        }
    }
}