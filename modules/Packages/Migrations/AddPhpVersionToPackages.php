<?php

declare(strict_types=1);

namespace Modules\Packages\Migrations;

use App\Foundation\Database\Migration;

/**
 * Per-package default PHP version: new accounts get their PHP-FPM master on
 * this version, and websites created without an explicit choice inherit it.
 * Existing sites are never re-pointed by a package edit (state).
 */
class AddPhpVersionToPackages extends Migration
{
    public function up(): void
    {
        try {
            $this->db->statement("ALTER TABLE `packages` ADD COLUMN `php_version` VARCHAR(8) NOT NULL DEFAULT '84'");
        } catch (\Throwable $e) {
            // already exists
        }
    }
}