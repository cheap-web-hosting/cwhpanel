<?php

declare(strict_types=1);

namespace Modules\Authentication\Migrations;

use App\Foundation\Database\Migration;

class CreatePasswordResetTokensTable extends Migration
{
    public function up(): void
    {
        $this->createTable('password_reset_tokens', function ($t) {
            $t->id();
            $t->string('email', 255);
            $t->string('token', 255);
            $t->timestamp('created_at');
            $t->index('email');
        });
    }
}
