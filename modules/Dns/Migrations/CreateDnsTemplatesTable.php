<?php

declare(strict_types=1);

namespace Modules\Dns\Migrations;

use App\Foundation\Database\Migration;

class CreateDnsTemplatesTable extends Migration
{
    public function up(): void
    {
        $this->createTable('dns_templates', function ($t) {
            $t->id();
            $t->string('name', 191);
            $t->nullableText('description');
            $t->text('records');
            $t->boolean('is_system');
            $t->timestamps();
            $t->unique('name');
        });
    }
}