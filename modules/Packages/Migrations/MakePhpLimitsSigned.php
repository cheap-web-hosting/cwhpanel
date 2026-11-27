<?php

declare(strict_types=1);

namespace Modules\Packages\Migrations;

use App\Foundation\Database\Migration;

/**
 * The PHP memory / execution-time package limits accept -1 as the explicit
 * "unlimited" sentinel (0 keeps meaning "engine default"). INT UNSIGNED
 * cannot store that, so both columns become signed.
 */
class MakePhpLimitsSigned extends Migration
{
    public function up(): void
    {
        foreach (['php_memory_limit_mb', 'php_max_execution_time'] as $column) {
            try {
                $this->db->statement("ALTER TABLE `packages` MODIFY `{$column}` INT NOT NULL DEFAULT 0");
            } catch (\Throwable $e) {
                // Best-effort convergence.
            }
        }
    }
}