<?php
declare(strict_types=1);
namespace Modules\Applications\Migrations;
use App\Foundation\Database\Migration;

/**
 * Push-to-deploy webhooks per app: HMAC secret + enabled flag (inbound
 * GitHub/GitLab delivery authenticates against the secret), plus last
 * deploy bookkeeping surfaced in the UI.
 */
class AddWebhookToApplications extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `applications` ADD COLUMN `webhook_secret` VARCHAR(64) NULL DEFAULT NULL'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `applications` ADD COLUMN `webhook_enabled` TINYINT(1) NOT NULL DEFAULT 0'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `applications` ADD COLUMN `last_deploy_at` TIMESTAMP NULL DEFAULT NULL'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `applications` ADD COLUMN `last_deploy_status` VARCHAR(32) NULL DEFAULT NULL'); } catch (\Throwable $e) {}
    }
}
