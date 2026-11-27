<?php
declare(strict_types=1);
namespace Modules\Api\Migrations;
use App\Foundation\Database\Migration;
class CreateApiKeysTable extends Migration {
    public function up(): void {
        $this->createTable('api_keys', function ($t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('name', 191); $t->string('key', 64); $t->string('status', 32); $t->timestamp('last_used_at', true); $t->timestamps(); $t->unique('key'); $t->index('user_id');
        });
    }
}