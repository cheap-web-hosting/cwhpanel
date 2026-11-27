<?php
declare(strict_types=1);
namespace Modules\Databases\Migrations;
use App\Foundation\Database\Migration;
class CreateDatabaseUsersTable extends Migration {
    public function up(): void {
        $this->createTable('hosting_db_users', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('username', 64); $t->string('password', 255); $t->unsignedBigInteger('database_id'); $t->timestamps(); $t->unique('username'); $t->index('account_id');
        });
    }
}