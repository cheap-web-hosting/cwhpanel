<?php
declare(strict_types=1);
namespace Modules\Security\Migrations;
use App\Foundation\Database\Migration;
class CreateFirewallAttacksTable extends Migration {
    public function up(): void {
        $this->createTable('firewall_attacks', function ($t) {
            $t->id();
            $t->string('source_ip', 45);
            $t->string('service', 64);
            $t->string('action', 16);
            $t->nullableString('reason', 255);
            $t->timestamp('created_at');
            $t->index('created_at');
            $t->index('source_ip');
        });
    }
}