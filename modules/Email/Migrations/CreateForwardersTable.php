<?php
declare(strict_types=1);
namespace Modules\Email\Migrations;
use App\Foundation\Database\Migration;
class CreateForwardersTable extends Migration {
    public function up(): void {
        $this->createTable('forwarders', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->unsignedBigInteger('domain_id'); $t->string('source', 255); $t->string('destination', 255); $t->timestamps(); $t->index('domain_id');
        });
    }
}