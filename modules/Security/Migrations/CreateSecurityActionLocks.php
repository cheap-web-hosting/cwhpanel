<?php

declare(strict_types=1);

namespace Modules\Security\Migrations;

use App\Foundation\Database\Migration;

/**
 * Per-account cooldown/single-flight state for security actions (audit #23).
 *
 * Customer WAF changes regenerate vhosts + reload nginx, and antivirus scans
 * run clamscan over a home tree. Both used to be triggerable without any
 * per-account limit, so one customer could reload nginx repeatedly or queue
 * many expensive scans. The guard uses MySQL named locks for single-flight and
 * this row for the cooldown window.
 */
class CreateSecurityActionLocks extends Migration
{
    public function up(): void
    {
        $exists = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `information_schema`.`tables`
              WHERE `table_schema` = DATABASE() AND `table_name` = 'security_action_locks'"
        );
        if ($exists > 0) {
            return;
        }

        $this->createTable('security_action_locks', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unique('account_id');
            $t->unsignedBigInteger('waf_last_at');
            $t->unsignedBigInteger('av_last_at');
        });
    }
}
