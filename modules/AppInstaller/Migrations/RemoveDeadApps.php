<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Remove Arastta + Grocy from the catalog — both are uninstallable by this
 * panel (proven by extraction + boot attempts, not guesswork):
 *
 * - Arastta 1.6.2 (2017, project dead): system/library/object.php declares
 *   `class Object`, reserved since PHP 7.2 — the framework fatals on every
 *   panel PHP version (oldest is 7.4) before any installer code runs. Its
 *   stock CLI additionally demands the long-removed mcrypt extension.
 * - Grocy 4.7.1: PrerequisiteChecker requires SQLite >= 3.40.0, but every
 *   PHP build on this host links the Rocky 9 system lib (3.34.1) — it
 *   throws before serving anything (and it needs real PHP >= 8.5).
 *
 * No installations ever referenced either slug.
 */
class RemoveDeadApps extends Migration {
    public function up(): void
    {
        foreach (['arastta', 'grocy'] as $slug) {
            try {
                $this->db->delete('ai_applications', '`slug` = ?', [$slug]);
            } catch (\Throwable $e) {
            }
        }
    }
}
