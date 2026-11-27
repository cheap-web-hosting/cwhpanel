<?php
declare(strict_types=1);
namespace Modules\Jobs\Migrations;
use App\Foundation\Database\Migration;
class AddScheduleToJobs extends Migration {
    public function up(): void {
        foreach ([
            "ALTER TABLE `jobs` ADD COLUMN `available_at` TIMESTAMP NULL AFTER `status`",
            "ALTER TABLE `jobs` ADD COLUMN `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5 AFTER `attempts`",
        ] as $sql) {
            try { $this->db->statement($sql); } catch (\Throwable $e) {}
        }
        try { $this->db->statement('ALTER TABLE `jobs` ADD INDEX `jobs_available` (`status`, `available_at`)'); } catch (\Throwable $e) {}
    }
}