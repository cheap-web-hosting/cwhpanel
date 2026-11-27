<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;
class AddNginxLimitsToPackages extends Migration {
    public function up(): void {
        // Per-account nginx worker sizing + upload cap, tied to the package.
        // workers: nginx worker_processes (1-8); max_body_mb: client_max_body_size.
        try { $this->db->statement("ALTER TABLE `packages` ADD COLUMN `nginx_workers` INT UNSIGNED NOT NULL DEFAULT 2 AFTER `max_processes`"); } catch (\Throwable $e) {}
        try { $this->db->statement("ALTER TABLE `packages` ADD COLUMN `nginx_max_body_mb` INT UNSIGNED NOT NULL DEFAULT 32 AFTER `nginx_workers`"); } catch (\Throwable $e) {}
    }
}
