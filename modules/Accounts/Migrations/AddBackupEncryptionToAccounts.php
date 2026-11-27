<?php
declare(strict_types=1);
namespace Modules\Accounts\Migrations;
use App\Foundation\Database\Migration;

/** Per-account backup encryption toggle (sealed with BackupCrypto). */
class AddBackupEncryptionToAccounts extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `accounts` ADD COLUMN `backup_encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `mcs_category`'); } catch (\Throwable $e) {}
    }
}
