<?php

declare(strict_types=1);

namespace Modules\Authorization\Migrations;

use App\Foundation\Database\Migration;

class CreateRolesTable extends Migration
{
    public function up(): void
    {
        $this->createTable('roles', function ($t) {
            $t->id();
            $t->string('name', 191);
            $t->string('slug', 191);
            $t->boolean('is_system');
            $t->timestamps();
            $t->unique('slug');
        });
    }
}
