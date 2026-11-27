<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;

/**
 * MySQL Governor limits per package (CloudLinux Governor equivalent, panel
 * half): server-enforced per-user caps applied with ALTER USER ... WITH,
 * plus a per-sweep statement-timeout killer (see MysqlGovernorService).
 * 0 = unlimited for the per-hour caps and the killer (matches MySQL).
 */
class AddMysqlGovernorToPackages extends Migration {
    public function up(): void {
        $cols = [
            "ALTER TABLE `packages` ADD COLUMN `mysql_max_user_connections` INT UNSIGNED NOT NULL DEFAULT 20 AFTER `mailbox_max_out_per_hour`",
            "ALTER TABLE `packages` ADD COLUMN `mysql_max_queries_per_hour` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `mysql_max_user_connections`",
            "ALTER TABLE `packages` ADD COLUMN `mysql_max_updates_per_hour` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `mysql_max_queries_per_hour`",
            "ALTER TABLE `packages` ADD COLUMN `mysql_max_statement_sec` INT UNSIGNED NOT NULL DEFAULT 120 AFTER `mysql_max_updates_per_hour`",
        ];
        foreach ($cols as $sql) {
            try { $this->db->statement($sql); } catch (\Throwable $e) {}
        }
    }
}
