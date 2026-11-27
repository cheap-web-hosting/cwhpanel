<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;
class AddCustomNginxToWebsites extends Migration {
    public function up(): void {
        // Free-form nginx directives injected into the site's server block.
        // Validated with nginx -t before going live; on isolated accounts a
        // bad snippet can only break that account's worker, never the front door.
        try { $this->db->statement("ALTER TABLE `websites` ADD COLUMN `custom_nginx` TEXT NULL DEFAULT NULL AFTER `nginx_config`"); } catch (\Throwable $e) {}
    }
}
