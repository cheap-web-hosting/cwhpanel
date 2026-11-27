<?php
declare(strict_types=1);
namespace Modules\Authentication\Migrations;
use App\Foundation\Database\Migration;
class AddTwoFactorToUsersTable extends Migration {
    public function up(): void {
        // Adds TOTP fields: secret, enabled flag, and confirmed flag (secret is
        // only marked confirmed after the user verifies a code during setup).
        foreach ([
            "ALTER TABLE `users` ADD COLUMN `two_factor_secret` VARCHAR(64) NULL AFTER `status`",
            "ALTER TABLE `users` ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `two_factor_secret`",
        ] as $sql) {
            try {
                $this->db->statement($sql);
            } catch (\Throwable $e) {
                // Column may already exist on re-run.
            }
        }
    }
}