<?php

declare(strict_types=1);

namespace Modules\Email\Migrations;

use App\Foundation\Database\Migration;

class CreateMailAutorespondersTable extends Migration
{
    public function up(): void
    {
        $this->createTable('mail_autoresponders', function ($t) {
            $t->id();
            $t->unsignedBigInteger('mailbox_id');
            $t->boolean('enabled');
            $t->string('subject', 255);
            $t->text('body');
            $t->timestamps();
            $t->unique('mailbox_id');
        });
    }
}
