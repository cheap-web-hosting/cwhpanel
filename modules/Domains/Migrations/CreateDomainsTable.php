<?php

declare(strict_types=1);

namespace Modules\Domains\Migrations;

use App\Foundation\Database\Migration;

class CreateDomainsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('domains', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name', 255);
            $t->string('type', 32);
            $t->nullableString('document_root', 255);
            $t->nullableString('redirect_url', 255);
            $t->string('status', 32);
            $t->timestamps();
            $t->unique('name');
            $t->index('account_id');
            $t->index('type');
        });
    }
}
