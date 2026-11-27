<?php
declare(strict_types=1);
namespace Modules\Network\Migrations;
use App\Foundation\Database\Migration;

/**
 * IPv4 pool for dedicated website assignments (shared fallback needs no
 * row). status: free|assigned. family is 'ipv4' today (v6 comes from the
 * routed /64 deterministically, not from a pool).
 */
class CreateIpPoolTable extends Migration {
    public function up(): void {
        try {
            $this->createTable('ip_pool', function ($t) {
                $t->id(); $t->string('address', 45); $t->string('family', 8); $t->string('status', 16);
                $t->nullableBigInteger('website_id', true); $t->nullableString('note', 255);
                $t->timestamps(); $t->index('status'); $t->index('website_id');
            });
        } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `ip_pool` ADD UNIQUE KEY `ip_pool_address_unique` (`address`)'); } catch (\Throwable $e) {}
    }
}
