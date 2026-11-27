<?php
declare(strict_types=1);
namespace Modules\Email\Migrations;
use App\Foundation\Database\Migration;
class CreateMailDomainsTable extends Migration {
    public function up(): void {
        $this->createTable('mail_domains', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('domain', 255); $t->string('status', 32); $t->timestamps(); $t->unique('domain'); $t->index('account_id');
        });
    }
}