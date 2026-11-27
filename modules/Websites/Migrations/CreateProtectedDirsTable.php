<?php

declare(strict_types=1);

namespace Modules\Websites\Migrations;

use App\Foundation\Database\Migration;

class CreateProtectedDirsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('website_protected_dirs', function ($t) {
            $t->id();
            $t->unsignedBigInteger('website_id');
            $t->string('path', 255);
            $t->string('title', 255);
            $t->boolean('enabled');
            $t->timestamps();
            $t->index('website_id');
        });
    }
}
