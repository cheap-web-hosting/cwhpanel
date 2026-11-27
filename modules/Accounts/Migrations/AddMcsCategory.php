<?php
declare(strict_types=1);
namespace Modules\Accounts\Migrations;
use App\Foundation\Database\Migration;
class AddMcsCategory extends Migration {
    public function up(): void {
        // Assign each account a unique SELinux MCS category (s0:cN). N is the
        // account's own id within the c0.c1023 range. Null/0 = no category
        // (isolation via UID/GID + content type only).
        try { $this->db->statement("ALTER TABLE `accounts` ADD COLUMN `mcs_category` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`"); } catch (\Throwable $e) {}
    }
}