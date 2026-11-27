<?php
declare(strict_types=1);
namespace Modules\Domains\Migrations;
use App\Foundation\Database\Migration;
class AddParentDomain extends Migration {
    public function up(): void {
        try {
            $this->db->statement("ALTER TABLE `domains` ADD COLUMN `parent_domain_id` BIGINT UNSIGNED NULL AFTER `type`");
        } catch (\Throwable $e) {}
    }
}
