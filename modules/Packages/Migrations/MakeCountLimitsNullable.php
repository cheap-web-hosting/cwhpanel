<?php

declare(strict_types=1);

namespace Modules\Packages\Migrations;

use App\Foundation\Database\Migration;

/**
 * Count limits move from "0 = unlimited" to the unambiguous pair:
 *
 *   NULL = unlimited (no cap)
 *   0    = NOT allowed (e.g. no add-on domains at all)
 *
 * Capacity limits (disk, memory, bandwidth, PHP, storage quotas, …) keep
 * 0 = unlimited/disabled — a zero-capacity account is meaningless, and their
 * engines express "no cap" with 0.
 *
 * Existing rows used 0 for "unlimited", so every current 0 becomes NULL: a
 * migration must never silently turn an unlimited package into "nothing
 * allowed" (that would block every provisioned account's additions).
 */
class MakeCountLimitsNullable extends Migration
{
    public const COUNT_COLUMNS = [
        'max_domains',
        'max_subdomains',
        'max_databases',
        'max_email_accounts',
        'max_ftp_accounts',
        'max_cron_jobs',
        'max_nodejs_apps',
        'max_python_apps',
    ];

    public function up(): void
    {
        foreach (self::COUNT_COLUMNS as $column) {
            try {
                $this->db->statement(
                    "ALTER TABLE `packages` MODIFY `{$column}` INT UNSIGNED NULL DEFAULT NULL"
                );
                $this->db->statement("UPDATE `packages` SET `{$column}` = NULL WHERE `{$column}` = 0");
            } catch (\Throwable $e) {
                // Best-effort convergence; re-runs are safe.
            }
        }
    }
}