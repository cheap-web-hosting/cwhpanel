<?php
declare(strict_types=1);
namespace Modules\Security\Migrations;
use App\Foundation\Database\Migration;
class CreateSecurityScansTable extends Migration {
    public function up(): void {
        $this->createTable('security_scans', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('status', 32); $t->unsignedInteger('scanned_files'); $t->unsignedInteger('infected_files'); $t->nullableText('infected_list'); $t->unsignedInteger('duration_seconds'); $t->timestamp('created_at');
        });
    }
}