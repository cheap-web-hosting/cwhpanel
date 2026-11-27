<?php
declare(strict_types=1);
namespace Modules\Email\Migrations;
use App\Foundation\Database\Migration;
class AddCatchallToMailDomains extends Migration {
    public function up(): void {
        // Default (catch-all) address for a mail domain. NULL/empty = bounce
        // unknown recipients (postfix default); otherwise mail to
        // non-existent addresses is redirected to this address.
        try { $this->db->statement("ALTER TABLE `mail_domains` ADD COLUMN `catchall` VARCHAR(255) NULL DEFAULT NULL AFTER `status`"); } catch (\Throwable $e) {}
    }
}
