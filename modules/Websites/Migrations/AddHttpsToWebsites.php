<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;

/**
 * Per-site HTTPS behavior: force_https (http->https 301, ACME path exempt)
 * and hsts_max_age (Strict-Transport-Security seconds, 0 = off). HSTS is
 * emitted only while a certificate is installed for the domain.
 */
class AddHttpsToWebsites extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `websites` ADD COLUMN `force_https` TINYINT(1) NOT NULL DEFAULT 0 AFTER `suspended_bandwidth`'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `websites` ADD COLUMN `hsts_max_age` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `force_https`'); } catch (\Throwable $e) {}
    }
}
