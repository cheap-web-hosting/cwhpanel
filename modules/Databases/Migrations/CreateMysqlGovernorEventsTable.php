<?php
declare(strict_types=1);
namespace Modules\Databases\Migrations;
use App\Foundation\Database\Migration;

/** Audit trail of queries killed by the MySQL Governor sweep. */
class CreateMysqlGovernorEventsTable extends Migration {
    public function up(): void {
        $this->createTable('mysql_governor_events', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->string('db_user', 64); $t->unsignedBigInteger('mysql_id'); $t->string('db', 191); $t->nullableText('query_prefix'); $t->unsignedInteger('time_sec'); $t->timestamps(); $t->index('account_id');
        });
    }
}
