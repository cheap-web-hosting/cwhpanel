<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;
class AddLeechProtection extends Migration {
    public function up(): void {
        // max_logins: max distinct client IPs per user per 24h before the
        // user is flagged (NULL/0 = off). suspended: user omitted from the
        // htpasswd rewrite, so their password stops working immediately.
        try { $this->db->statement("ALTER TABLE `website_protected_dirs` ADD COLUMN `max_logins` INT UNSIGNED NULL DEFAULT NULL AFTER `enabled`"); } catch (\Throwable $e) {}
        try { $this->db->statement("ALTER TABLE `website_dir_users` ADD COLUMN `suspended` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password`"); } catch (\Throwable $e) {}
    }
}
