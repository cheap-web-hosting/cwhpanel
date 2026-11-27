<?php

declare(strict_types=1);

namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;

class CreateAuditLogsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('audit_logs', function ($t) {
            $t->id();
            $t->nullableBigInteger('user_id', true);
            $t->string('action', 128);
            $t->nullableString('resource_type', 64);
            $t->nullableString('resource_id', 64);
            $t->nullableString('ip_address', 45);
            $t->nullableString('user_agent', 255);
            $t->nullableText('metadata');
            $t->timestamp('created_at');
            $t->index('action');
            $t->index('user_id');
            $t->index('created_at');
        });
    }
}
