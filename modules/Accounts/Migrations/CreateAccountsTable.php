<?php

declare(strict_types=1);

namespace Modules\Accounts\Migrations;

use App\Foundation\Database\Migration;

class CreateAccountsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('accounts', function ($t) {
            $t->id();
            $t->nullableBigInteger('user_id', true);
            $t->string('username', 64);
            $t->string('domain', 255);
            $t->unsignedBigInteger('package_id');
            $t->string('status', 32);
            $t->nullableString('home_dir', 255);
            $t->nullableString('email', 255);
            $t->timestamps();
            $t->unique('username');
            $t->index('package_id');
            $t->index('status');
        });
    }
}
