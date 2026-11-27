<?php
declare(strict_types=1);
namespace Modules\Security\Migrations;
use App\Foundation\Database\Migration;
class AddOwnerToFirewallRules extends Migration {
    public function up(): void {
        // Owner of a customer-created block (admin rows stay NULL = admin-only).
        try { $this->db->statement('ALTER TABLE `firewall_rules` ADD COLUMN `account_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `action`'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `firewall_rules` ADD COLUMN `user_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `account_id`'); } catch (\Throwable $e) {}
    }
}
