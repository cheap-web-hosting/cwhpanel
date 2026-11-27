<?php

declare(strict_types=1);

namespace Modules\Websites\Migrations;

use App\Foundation\Database\Migration;

class CreateProtectedDirUsersTable extends Migration
{
    public function up(): void
    {
        $this->createTable('website_dir_users', function ($t) {
            $t->id();
            $t->unsignedBigInteger('protected_dir_id');
            $t->string('username', 64);
            $t->string('password', 255);
            $t->timestamps();
            $t->index('protected_dir_id');
        });
    }
}
