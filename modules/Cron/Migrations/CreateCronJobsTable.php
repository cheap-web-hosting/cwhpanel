<?php
declare(strict_types=1);
namespace Modules\Cron\Migrations;
use App\Foundation\Database\Migration;
class CreateCronJobsTable extends Migration {
    public function up(): void {
        $this->createTable('cron_jobs', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('schedule', 191); $t->string('command', 255); $t->string('status', 32); $t->timestamp('last_run_at', true); $t->nullableText('last_output'); $t->timestamps(); $t->index('account_id'); $t->index('status');
        });
    }
}