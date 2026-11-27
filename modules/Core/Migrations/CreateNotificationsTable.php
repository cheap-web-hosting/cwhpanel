<?php

declare(strict_types=1);

namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;

/**
 * In-app notifications (bell) for admin + customer users.
 *  - user_id: the recipient user row (admin or customer)
 *  - type/source: job.failed, app.done, watchdog.incident, vuln.critical, ...
 *  - url: deep link the bell item navigates to
 *  - read_at: null = unread
 */
class CreateNotificationsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('notifications', function ($t) {
            $t->id();
            $t->bigInteger('user_id', true);
            $t->string('type', 32);
            $t->string('title', 255);
            $t->nullableText('body');
            $t->nullableString('url', 255);
            $t->timestamp('read_at', true);
            $t->timestamp('created_at');
            $t->index('user_id');
            $t->index('read_at');
        });
    }

    public function down(): void
    {
        $this->dropTable('notifications');
    }
}
