<?php
declare(strict_types=1);
namespace Modules\Applications\Migrations;
use App\Foundation\Database\Migration;

/**
 * Resolved extra environment for catalog apps (e.g. N8N_PORT=22001),
 * baked at install time from the manifest with {PORT}/{HOST} substituted.
 * Passed to the process as KEY=VALUE pairs (agent-validated charset).
 */
class AddEnvToApplications extends Migration {
    public function up(): void {
        try {
            $this->db->statement('ALTER TABLE `applications` ADD COLUMN `env` TEXT NULL DEFAULT NULL AFTER `base_path`');
        } catch (\Throwable $e) {}
    }
}
