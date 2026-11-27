<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Link installations to the Applications-module row they provisioned
 * (app-type installs: python-pip / node-npm / node-archive), so uninstall
 * tears the process down too. NULL for classic docroot installs.
 */
class AddApplicationIdToInstallations extends Migration {
    public function up(): void {
        try {
            $this->db->statement('ALTER TABLE `ai_installations` ADD COLUMN `application_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `application_slug`');
        } catch (\Throwable $e) {}
        try {
            $this->db->statement('ALTER TABLE `ai_installations` ADD INDEX `ai_installations_app_idx` (`application_id`)');
        } catch (\Throwable $e) {}
    }
}
