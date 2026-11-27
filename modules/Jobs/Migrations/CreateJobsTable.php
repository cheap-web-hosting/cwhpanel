<?php
declare(strict_types=1);
namespace Modules\Jobs\Migrations;
use App\Foundation\Database\Migration;
class CreateJobsTable extends Migration {
    public function up(): void {
        $this->createTable('jobs', function ($t) {
            $t->id(); $t->string('type', 191); $t->string('status', 32); $t->nullableText('payload'); $t->nullableText('result'); $t->integer('attempts');             $t->timestamp('created_at'); $t->timestamp('started_at', true); $t->timestamp('finished_at', true); $t->timestamp('updated_at', true); $t->index('status'); $t->index('type');
        });
    }
}