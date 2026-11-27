<?php
declare(strict_types=1);
namespace Modules\Dns\Migrations;

use App\Foundation\Database\Migration;

/**
 * Optional second nameserver per zone. `nameserver` (first NS) is already
 * nullable — both are customer-supplied now, never the panel's own NS by
 * default (see Modules\Domains\Migrations\AddDomainNameservers).
 */
class AddZoneSecondNameserver extends Migration
{
    public function up(): void
    {
        try {
            $this->db->statement(
                "ALTER TABLE `dns_zones` ADD COLUMN `nameserver2` VARCHAR(191) NULL AFTER `nameserver`"
            );
        } catch (\Throwable $e) {
            // Column already present.
        }
    }
}
