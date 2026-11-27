<?php
declare(strict_types=1);
namespace Modules\Cron\Migrations;
use App\Foundation\Database\Migration;

/**
 * Run-result columns: exit code + duration back the "last run" display
 * next to the pre-existing last_run_at/last_output.
 */
class AddCronRunStats extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `cron_jobs` ADD COLUMN `last_exit` INT NULL DEFAULT NULL'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `cron_jobs` ADD COLUMN `last_duration_ms` INT NULL DEFAULT NULL'); } catch (\Throwable $e) {}
    }
}
