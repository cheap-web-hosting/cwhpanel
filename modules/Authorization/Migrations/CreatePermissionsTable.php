<?php

declare(strict_types=1);

namespace Modules\Authorization\Migrations;

use App\Foundation\Database\Migration;

class CreatePermissionsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('permissions', function ($t) {
            $t->id();
            $t->string('name', 191);
            $t->string('module', 64);
            $t->timestamps();
            $t->unique('name');
        });
    }
}
