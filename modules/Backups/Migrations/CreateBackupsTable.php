<?php
declare(strict_types=1);
namespace Modules\Backups\Migrations;
use App\Foundation\Database\Migration;
class CreateBackupsTable extends Migration {
    public function up(): void {
        $this->createTable('backups', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('name', 255); $t->string('type', 32); $t->unsignedBigInteger('size_bytes'); $t->string('status', 32); $t->timestamp('created_at'); $t->index('account_id'); $t->index('status');
        });
    }
}