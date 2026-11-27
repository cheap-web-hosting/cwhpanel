<?php
declare(strict_types=1);
namespace Modules\Api\Migrations;
use App\Foundation\Database\Migration;
class CreateWebhooksTable extends Migration {
    public function up(): void {
        $this->createTable('webhooks', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('event', 191); $t->string('url', 255); $t->string('status', 32); $t->timestamps(); $t->index('account_id');
        });
    }
}