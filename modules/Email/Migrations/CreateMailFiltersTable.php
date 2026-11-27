<?php

declare(strict_types=1);

namespace Modules\Email\Migrations;

use App\Foundation\Database\Migration;

class CreateMailFiltersTable extends Migration
{
    public function up(): void
    {
        $this->createTable('mail_filters', function ($t) {
            $t->id();
            $t->unsignedBigInteger('mailbox_id');
            $t->integer('position', true);
            $t->string('field', 32);
            $t->string('operator', 16);
            $t->string('value', 255);
            $t->string('action', 16);
            $t->nullableString('target', 255);
            $t->boolean('enabled');
            $t->timestamps();
            $t->index('mailbox_id');
        });
    }
}
