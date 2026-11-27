<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;

/**
 * Admin suspend flag, separate from user-controlled maintenance_mode and
 * the bandwidth billing hold: account suspend parks every website (503)
 * and unsuspend clears it. Customer maintenance toggles keep working
 * independently underneath (their setting is preserved across suspend).
 */
class AddSuspendedAccountToWebsites extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `websites` ADD COLUMN `suspended_account` TINYINT(1) NOT NULL DEFAULT 0 AFTER `suspended_bandwidth`'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `accounts` ADD COLUMN `suspend_state` TEXT NULL DEFAULT NULL AFTER `status`'); } catch (\Throwable $e) {}
    }
}
