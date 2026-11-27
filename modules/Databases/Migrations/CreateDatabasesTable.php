<?php
declare(strict_types=1);
namespace Modules\Databases\Migrations;
use App\Foundation\Database\Migration;
class CreateDatabasesTable extends Migration {
    public function up(): void {
        $this->createTable('hosting_databases', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('name', 191); $t->string('username', 191); $t->string('password', 255); $t->string('status', 32); $t->timestamps(); $t->unique('name'); $t->index('account_id');
        });
    }
}