<?php
declare(strict_types=1);
namespace Modules\Cron\Migrations;
use App\Foundation\Database\Migration;

/**
 * Opt-in isolation for cron jobs: `jailed=1` runs the job inside the account's
 * mount-namespace jail (M2 CageFS-lite) so it cannot see other accounts,
 * panel secrets, backups or /root. Applies to manual "Run now"; the crontab
 * line itself is unchanged (scheduled runs are not jailed yet, documented).
 */
class AddJailedToCronJobs extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `cron_jobs` ADD COLUMN `jailed` TINYINT(1) NOT NULL DEFAULT 0'); } catch (\Throwable $e) {}
    }
}
