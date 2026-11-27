<?php

declare(strict_types=1);

namespace Modules\Authentication\Migrations;

use App\Foundation\Database\Migration;

class CreateLoginAttemptsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('login_attempts', function ($t) {
            $t->id();
            $t->string('key', 191);
            $t->timestamp('created_at');
            $t->index('key');
            $t->index('created_at');
        });
    }
}
