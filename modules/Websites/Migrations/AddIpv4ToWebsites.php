<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;

/**
 * Dedicated IPv4 override per website (NULL = shared server IPv4 fallback
 * for non-IPv6-capable devices). Must reference ip_pool when set.
 */
class AddIpv4ToWebsites extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `websites` ADD COLUMN `ipv4_address` VARCHAR(45) NULL DEFAULT NULL'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `websites` ADD INDEX `websites_ipv4_idx` (`ipv4_address`)'); } catch (\Throwable $e) {}
    }
}
