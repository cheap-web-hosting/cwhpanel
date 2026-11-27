<?php
declare(strict_types=1);
namespace Modules\Email\Migrations;
use App\Foundation\Database\Migration;
class AddRateLimitsAndCounters extends Migration {
    public function up(): void {
        foreach ([
            "ALTER TABLE `accounts` ADD COLUMN `mail_max_out_per_hour` INT UNSIGNED NOT NULL DEFAULT 100",
            "ALTER TABLE `accounts` ADD COLUMN `mail_max_out_per_day` INT UNSIGNED NOT NULL DEFAULT 500",
            "ALTER TABLE `mailboxes` ADD COLUMN `max_out_per_hour` INT UNSIGNED NOT NULL DEFAULT 50",
        ] as $sql) {
            try { $this->db->statement($sql); } catch (\Throwable $e) {}
        }

        $this->createTable('mail_counters', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('mailbox', 255);
            $t->unsignedInteger('bucket_ts');   // hour bucket (unix ts of the hour)
            $t->unsignedInteger('count');
            $t->index('account_id');
            $t->index('mailbox');
            $t->index('bucket_ts');
        });

        try {
            $this->db->statement('ALTER TABLE `mail_counters` ADD UNIQUE KEY `mail_counters_uniq` (`account_id`, `mailbox`, `bucket_ts`)');
        } catch (\Throwable $e) {}
    }
}