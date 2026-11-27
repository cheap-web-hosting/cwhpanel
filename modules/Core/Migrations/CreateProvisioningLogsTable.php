<?php

declare(strict_types=1);

namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;

class CreateProvisioningLogsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('provisioning_logs', function ($t) {
            $t->id();
            $t->string('operation', 64);
            $t->string('status', 32);
            $t->nullableText('command');
            $t->nullableText('args');
            $t->nullableText('output');
            $t->timestamp('created_at');
            $t->index('operation');
            $t->index('created_at');
        });
    }
}