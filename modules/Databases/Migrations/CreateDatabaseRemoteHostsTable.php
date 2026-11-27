<?php
declare(strict_types=1);
namespace Modules\Databases\Migrations;
use App\Foundation\Database\Migration;
class CreateDatabaseRemoteHostsTable extends Migration {
    public function up(): void {
        // Extra hosts a database user may connect from ('localhost' is always
        // implicit and never stored here). Host is a literal IPv4 (firewalled
        // per-IP), a hostname, or a % pattern for MySQL matching.
        // No UNIQUE: the schema builder has no composite-unique helper, so the
        // (db_user_id, host) dedup lives in DatabaseService::allowHost().
        $this->createTable('hosting_db_hosts', function ($t) {
            $t->id(); $t->unsignedBigInteger('db_user_id'); $t->string('host', 255); $t->timestamps(); $t->index('db_user_id');
        });
    }
}
