<?php
declare(strict_types=1);
namespace Modules\Applications\Migrations;
use App\Foundation\Database\Migration;
class AddRuntimeVersionToApplications extends Migration {
    public function up(): void {
        try { $this->db->statement("ALTER TABLE `applications` ADD COLUMN `runtime_version` VARCHAR(16) NULL DEFAULT NULL AFTER `runtime`"); } catch (\Throwable $e) {}
    }
}
