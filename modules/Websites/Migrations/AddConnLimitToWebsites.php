<?php

declare(strict_types=1);

namespace Modules\Websites\Migrations;

use App\Foundation\Database\Migration;

/**
 * Per-site concurrent-connection override (nginx limit_conn). NULL means
 * "inherit the package's max_connections"; 0 means "no cap for this site".
 */
class AddConnLimitToWebsites extends Migration
{
    public function up(): void
    {
        try {
            $this->db->statement("ALTER TABLE `websites` ADD COLUMN `max_connections` INT UNSIGNED NULL DEFAULT NULL AFTER `rate_limit_burst`");
        } catch (\Throwable $e) {
            // Column already present — idempotent re-run.
        }
    }
}