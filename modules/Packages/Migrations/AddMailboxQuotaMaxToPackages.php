<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;

/**
 * Per-mailbox size ceiling per package (megabytes). Caps the quota_mb a
 * mailbox may carry; Dovecot enforces it per user via quota_rule (see
 * mail.dovecot.sync), and the panel refuses larger values on create.
 * Default 1024 (1 GB). 0 = unlimited (no cap, Dovecot default rule applies).
 */
class AddMailboxQuotaMaxToPackages extends Migration {
    public function up(): void {
        $cols = [
            "ALTER TABLE `packages` ADD COLUMN `mailbox_quota_max_mb` INT UNSIGNED NOT NULL DEFAULT 1024 AFTER `mailbox_max_out_per_hour`",
        ];
        foreach ($cols as $sql) {
            try { $this->db->statement($sql); } catch (\Throwable $e) {}
        }
    }
}
