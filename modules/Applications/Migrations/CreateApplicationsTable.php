<?php
declare(strict_types=1);
namespace Modules\Applications\Migrations;
use App\Foundation\Database\Migration;
class CreateApplicationsTable extends Migration {
    public function up(): void {
        $this->createTable('applications', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('name', 64); $t->string('runtime', 16); $t->string('entry_point', 255); $t->string('work_dir', 255); $t->string('status', 32); $t->nullableString('git_url', 255); $t->nullableString('git_branch', 64); $t->timestamps(); $t->index('account_id'); $t->index('status');
        });
    }
}