<?php
declare(strict_types=1);
namespace Modules\Security\Migrations;
use App\Foundation\Database\Migration;
class CreateFirewallRulesTable extends Migration {
    public function up(): void {
        $this->createTable('firewall_rules', function ($t) {
            $t->id(); $t->string('ip_address', 45); $t->string('action', 16); $t->nullableString('reason', 255); $t->timestamp('created_at'); $t->unique('ip_address');
        });
    }
}