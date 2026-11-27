<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;
class AddRateLimitToWebsites extends Migration {
    public function up(): void {
        // Per-website bot shield (nginx limit_req). NULL rps/burst = inherit
        // the account package defaults (packages.nginx_req_per_sec/burst).
        try { $this->db->statement("ALTER TABLE `websites` ADD COLUMN `rate_limit_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `maintenance_mode`"); } catch (\Throwable $e) {}
        try { $this->db->statement("ALTER TABLE `websites` ADD COLUMN `rate_limit_rps` INT UNSIGNED NULL DEFAULT NULL AFTER `rate_limit_enabled`"); } catch (\Throwable $e) {}
        try { $this->db->statement("ALTER TABLE `websites` ADD COLUMN `rate_limit_burst` INT UNSIGNED NULL DEFAULT NULL AFTER `rate_limit_rps`"); } catch (\Throwable $e) {}
    }
}
