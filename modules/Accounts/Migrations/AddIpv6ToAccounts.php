<?php
declare(strict_types=1);
namespace Modules\Accounts\Migrations;
use App\Foundation\Database\Migration;

/**
 * Dedicated IPv6 per account (one address from the routed /64, used by all
 * websites + related services of the account; NULL = feature off/unassigned).
 */
class AddIpv6ToAccounts extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `accounts` ADD COLUMN `ipv6_address` VARCHAR(45) NULL DEFAULT NULL'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `accounts` ADD UNIQUE KEY `accounts_ipv6_unique` (`ipv6_address`)'); } catch (\Throwable $e) {}
    }
}
