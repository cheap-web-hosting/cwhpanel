<?php
declare(strict_types=1);
namespace Modules\Accounts\Migrations;
use App\Foundation\Database\Migration;
class AddNginxWorkerToggle extends Migration {
    public function up(): void {
        // Per-account nginx worker isolation. 0 (default) keeps the shared
        // front-door nginx serving the account's vhosts; 1 routes the account
        // through a dedicated nginx worker running as that user.
        try { $this->db->statement("ALTER TABLE `accounts` ADD COLUMN `nginx_worker_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `mcs_category`"); } catch (\Throwable $e) {}
    }
}