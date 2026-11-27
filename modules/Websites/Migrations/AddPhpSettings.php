<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;
class AddPhpSettings extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `websites` ADD COLUMN `php_settings` TEXT NULL AFTER `php_version`'); } catch (\Throwable $e) {}
    }
}