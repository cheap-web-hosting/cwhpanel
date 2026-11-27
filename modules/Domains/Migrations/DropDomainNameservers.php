<?php
declare(strict_types=1);
namespace Modules\Domains\Migrations;

use App\Foundation\Database\Migration;

/**
 * Nameservers are a SERVER setting (Admin → DNS → Nameservers); the
 * per-domain columns added while the feature was being built are removed.
 * `dns_zones.nameserver/nameserver2` stay: they record what a zone was
 * published with.
 */
class DropDomainNameservers extends Migration
{
    public function up(): void
    {
        foreach (['ns1', 'ns2'] as $column) {
            try {
                $this->db->statement("ALTER TABLE `domains` DROP COLUMN `{$column}`");
            } catch (\Throwable $e) {
                // Column never existed on a fresh install.
            }
        }
    }
}
