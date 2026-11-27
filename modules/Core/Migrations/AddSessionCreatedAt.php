<?php
declare(strict_types=1);
namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;

/**
 * Absolute session expiry support: add sessions.created_at (first-seen time).
 * Existing rows are backfilled to their last_activity so live sessions get a
 * full lifetime from the migration point instead of expiring immediately.
 */
class AddSessionCreatedAt extends Migration {
    public function up(): void {
        try {
            $this->db->statement('ALTER TABLE `sessions` ADD COLUMN `created_at` INT NULL AFTER `last_activity`');
        } catch (\Throwable $e) {
            // Column already exists (migration re-run).
        }
        try {
            $this->db->statement('UPDATE `sessions` SET `created_at` = `last_activity` WHERE `created_at` IS NULL');
        } catch (\Throwable $e) {}
    }
}
