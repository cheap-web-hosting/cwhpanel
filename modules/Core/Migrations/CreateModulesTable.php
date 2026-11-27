<?php

declare(strict_types=1);

namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;

class CreateModulesTable extends Migration
{
    public function up(): void
    {
        $this->createTable('modules', function ($t) {
            $t->stringPrimary('id', 64);
            $t->string('name', 255);
            $t->nullableString('version', 32);
            $t->boolean('is_enabled');
            $t->boolean('is_system');
            $t->timestamp('installed_at', true);
            $t->timestamp('updated_at', true);
            $t->index('is_enabled');
        });
    }
}
