<?php
declare(strict_types=1);
namespace Modules\Applications\Migrations;
use App\Foundation\Database\Migration;

/**
 * Reverse-proxy wiring for catalog apps (and future custom apps):
 * port (unique, NULL = no HTTP ingress), website_id + base_path (the
 * nginx location that proxies to it). NULL ports coexist freely in the
 * unique index — only assigned ports must be distinct.
 */
class AddProxyToApplications extends Migration {
    public function up(): void {
        try {
            $this->db->statement('ALTER TABLE `applications` ADD COLUMN `port` INT UNSIGNED NULL DEFAULT NULL AFTER `entry_point`');
        } catch (\Throwable $e) {}
        try {
            $this->db->statement('ALTER TABLE `applications` ADD COLUMN `website_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `port`');
        } catch (\Throwable $e) {}
        try {
            $this->db->statement('ALTER TABLE `applications` ADD COLUMN `base_path` VARCHAR(64) NULL DEFAULT NULL AFTER `website_id`');
        } catch (\Throwable $e) {}
        try {
            $this->db->statement('ALTER TABLE `applications` ADD UNIQUE INDEX `applications_port_unique` (`port`)');
        } catch (\Throwable $e) {}
        try {
            $this->db->statement('ALTER TABLE `applications` ADD INDEX `applications_website_idx` (`website_id`)');
        } catch (\Throwable $e) {}
    }
}
