<?php
declare(strict_types=1);
namespace Modules\Applications\Migrations;
use App\Foundation\Database\Migration;

/**
 * Staging relationship: a clone records the production app it was copied
 * from (sync flows staging -> parent). No FK — orphans stay usable rows.
 */
class AddAppStaging extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `applications` ADD COLUMN `cloned_from` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `env`'); } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `applications` ADD INDEX `applications_cloned_from_idx` (`cloned_from`)'); } catch (\Throwable $e) {}
    }
}
