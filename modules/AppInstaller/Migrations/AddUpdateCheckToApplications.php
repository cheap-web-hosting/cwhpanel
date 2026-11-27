<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Catalog freshness for the update checker: last version seen upstream +
 * when it was checked. update_available is derived (latest set, non-empty,
 * different from `version`), never stored.
 */
class AddUpdateCheckToApplications extends Migration {
    public function up(): void {
        try {
            $this->db->statement("ALTER TABLE `ai_applications` ADD COLUMN `latest_version` VARCHAR(64) NULL DEFAULT NULL AFTER `version`");
        } catch (\Throwable $e) {}
        try {
            // VARCHAR(32) in earlier revisions — some upstreams tag long
            // ("Mautic Community 7.2.0 Lynx Edition" era); 64 is plenty.
            $this->db->statement("ALTER TABLE `ai_applications` MODIFY COLUMN `latest_version` VARCHAR(64) NULL DEFAULT NULL");
        } catch (\Throwable $e) {}
        try {
            $this->db->statement('ALTER TABLE `ai_applications` ADD COLUMN `latest_checked_at` INT UNSIGNED NULL DEFAULT NULL AFTER `latest_version`');
        } catch (\Throwable $e) {}
    }
}
