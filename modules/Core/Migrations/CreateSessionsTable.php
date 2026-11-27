<?php

declare(strict_types=1);

namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;

class CreateSessionsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('sessions', function ($t) {
            $t->stringPrimary('id', 255);
            $t->nullableBigInteger('user_id', true);
            $t->nullableString('ip_address', 45);
            $t->nullableString('user_agent', 255);
            $t->text('payload');
            $t->integer('last_activity');
            $t->index('last_activity');
        });
    }
}
