<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;
class AddEmailLimits extends Migration {
    public function up(): void {
        foreach ([
            "ALTER TABLE `packages` ADD COLUMN `mail_max_out_per_hour` INT UNSIGNED NOT NULL DEFAULT 100",
            "ALTER TABLE `packages` ADD COLUMN `mail_max_out_per_day` INT UNSIGNED NOT NULL DEFAULT 500",
            "ALTER TABLE `packages` ADD COLUMN `mailbox_max_out_per_hour` INT UNSIGNED NOT NULL DEFAULT 50",
        ] as $sql) {
            try { $this->db->statement($sql); } catch (\Throwable $e) {}
        }
    }
}
