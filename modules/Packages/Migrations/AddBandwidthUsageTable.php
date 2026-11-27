<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;

/**
 * Monthly bandwidth accounting per account. Bytes are monotonic within a
 * month (max of samples, so log rotation can't shrink them); a new month
 * starts a fresh row, which also clears last month's notify/suspend flags.
 */
class AddBandwidthUsageTable extends Migration {
    public function up(): void {
        $this->createTable('bandwidth_usage', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('month', 7); $t->unsignedBigInteger('bytes');
            $t->boolean('notified80'); $t->boolean('notified95'); $t->boolean('suspended'); $t->timestamps();
            $t->index('account_id');
        });
        try { $this->db->statement('ALTER TABLE `bandwidth_usage` ADD UNIQUE KEY `uq_account_month` (`account_id`, `month`)'); } catch (\Throwable $e) {}
    }
}
