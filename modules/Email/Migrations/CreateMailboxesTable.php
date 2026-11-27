<?php
declare(strict_types=1);
namespace Modules\Email\Migrations;
use App\Foundation\Database\Migration;
class CreateMailboxesTable extends Migration {
    public function up(): void {
        $this->createTable('mailboxes', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->unsignedBigInteger('domain_id'); $t->string('email', 255); $t->string('password', 255); $t->unsignedInteger('quota_mb'); $t->string('status', 32); $t->timestamps(); $t->unique('email'); $t->index('domain_id');
        });
    }
}