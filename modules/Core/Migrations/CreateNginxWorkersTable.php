<?php

declare(strict_types=1);

namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;

class CreateNginxWorkersTable extends Migration
{
    public function up(): void
    {
        $this->createTable('nginx_workers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedInteger('port');
            $t->string('status', 32);
            $t->timestamps();
            $t->unique('account_id');
            $t->unique('port');
            // One worker row per account; port is derived from account_id so the
            // two single-column uniques above also enforce 1:1. Kept separate
            // because the schema builder has no composite-unique helper.
        });
    }
}