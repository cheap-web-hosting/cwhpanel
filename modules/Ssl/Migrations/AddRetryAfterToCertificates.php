<?php

declare(strict_types=1);

namespace Modules\Ssl\Migrations;

use App\Foundation\Database\Migration;

/**
 * Rate-limit-aware renewal: track when a domain may be retried (Let's Encrypt
 * 429 "too many certificates" / Retry-After / failed-validation backoff) and
 * the last error, so the renewal sweep doesn't blind re-issue.
 */
class AddRetryAfterToCertificates extends Migration
{
    public function up(): void
    {
        try {
            $this->db->statement("ALTER TABLE `certificates` ADD COLUMN `retry_after` TIMESTAMP NULL DEFAULT NULL AFTER `status`");
        } catch (\Throwable $e) {
        }
        try {
            $this->db->statement("ALTER TABLE `certificates` ADD COLUMN `last_error` TEXT NULL AFTER `retry_after`");
        } catch (\Throwable $e) {
        }
        try {
            $this->db->statement("ALTER TABLE `certificates` ADD INDEX `certificates_retry_after_idx` (`retry_after`)");
        } catch (\Throwable $e) {
        }
    }
}