<?php

declare(strict_types=1);

namespace Modules\Packages\Migrations;

use App\Foundation\Database\Migration;

/**
 * Additional hosting-package resource limits (see docs/PACKAGE_LIMITS_PLAN.md).
 *
 * Every column is INT UNSIGNED NOT NULL DEFAULT 0 and 0 means "unlimited /
 * engine default" — the same fail-open semantics as the existing max_* columns,
 * so legacy packages keep behaving exactly as before until an admin sets a cap.
 *
 * Columns are APPENDED (no AFTER clauses) on purpose: migration order is not
 * something this file should depend on, and a failed ALTER is swallowed to
 * stay idempotent — an anchor on a not-yet-added column would silently drop
 * the column instead of failing loudly.
 *
 *   disk_inodes              XFS inode soft/hard limit (0 = none)
 *   max_iops                 cgroup IORead/WriteIOPSMax on the account slice
 *   php_max_children         pm.max_children for the account's PHP master/pool
 *   php_memory_limit_mb      package ceiling for php_admin_value[memory_limit]
 *   php_max_execution_time   package ceiling for max_execution_time (seconds)
 *   max_node_memory_mb       MemoryMax on supervised Node.js units
 *   max_python_memory_mb     MemoryMax on supervised Python units
 *   database_quota_mb        per-account database storage ceiling
 *   max_connections          nginx limit_conn per site (web connections)
 *   backup_quota_mb          per-account backup storage ceiling
 */
class AddResourceLimitsToPackages extends Migration
{
    public const COLUMNS = [
        'disk_inodes' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'max_iops' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'php_max_children' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'php_memory_limit_mb' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'php_max_execution_time' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'max_node_memory_mb' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'max_python_memory_mb' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'database_quota_mb' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'max_connections' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'backup_quota_mb' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $name => $definition) {
            try {
                $this->db->statement("ALTER TABLE `packages` ADD COLUMN `{$name}` {$definition}");
            } catch (\Throwable $e) {
                // Column already present — idempotent re-run.
            }
        }
    }
}