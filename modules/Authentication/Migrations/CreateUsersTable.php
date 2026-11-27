<?php

declare(strict_types=1);

namespace Modules\Authentication\Migrations;

use App\Foundation\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->createTable('users', function ($t) {
            $t->id();
            $t->string('email', 255);
            $t->string('password', 255);
            $t->string('name', 191);
            $t->nullableBigInteger('role_id', true);
            $t->boolean('is_admin');
            $t->string('status', 32);
            $t->timestamp('last_login_at', true);
            $t->timestamps();
            $t->unique('email');
        });
    }
}
