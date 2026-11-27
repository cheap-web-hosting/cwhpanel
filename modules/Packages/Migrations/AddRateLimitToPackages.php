<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;
class AddRateLimitToPackages extends Migration {
    public function up(): void {
        // Per-package bot-shield defaults: requests/sec + burst size.
        // Websites with NULL overrides inherit these; editing them re-applies
        // to inheriting sites (see PackageController::applyRateLimits).
        try { $this->db->statement("ALTER TABLE `packages` ADD COLUMN `nginx_req_per_sec` INT UNSIGNED NOT NULL DEFAULT 20 AFTER `nginx_max_body_mb`"); } catch (\Throwable $e) {}
        try { $this->db->statement("ALTER TABLE `packages` ADD COLUMN `nginx_req_burst` INT UNSIGNED NOT NULL DEFAULT 40 AFTER `nginx_req_per_sec`"); } catch (\Throwable $e) {}
    }
}
