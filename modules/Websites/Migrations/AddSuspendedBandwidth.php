<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;

/**
 * Billing hold flag, separate from user-controlled maintenance_mode: the
 * bandwidth sweep sets it on overage (sites answer 503), clears it when
 * usage drops back under quota (month rollover) or an admin unsuspends.
 * Only admins can clear it; customers just see the badge.
 */
class AddSuspendedBandwidth extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `websites` ADD COLUMN `suspended_bandwidth` TINYINT(1) NOT NULL DEFAULT 0 AFTER `maintenance_mode`'); } catch (\Throwable $e) {}
    }
}
