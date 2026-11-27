<?php
declare(strict_types=1);
namespace Modules\Network\Migrations;
use App\Foundation\Database\Migration;

/** Prefix length for pool IPv4 binds (NULL = /32 default). */
class AddPoolPrefixlen extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `ip_pool` ADD COLUMN `prefixlen` INT UNSIGNED NULL DEFAULT NULL'); } catch (\Throwable $e) {}
    }
}
