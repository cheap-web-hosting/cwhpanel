<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;
class AddMaintenanceModeToWebsites extends Migration {
    public function up(): void {
        // Per-website maintenance mode: the vhost answers 503 for everything
        // (front-door proxy on isolated accounts, direct location otherwise).
        try { $this->db->statement("ALTER TABLE `websites` ADD COLUMN `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0 AFTER `custom_nginx`"); } catch (\Throwable $e) {}
    }
}
