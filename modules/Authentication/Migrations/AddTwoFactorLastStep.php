<?php
declare(strict_types=1);
namespace Modules\Authentication\Migrations;
use App\Foundation\Database\Migration;
/**
 * TOTP replay protection: remember the last accepted RFC 6238 time-step.
 *
 * verify() was stateless, so a code observed once (AiTM proxy, screen share,
 * shoulder surf) stayed valid for its whole ±1 window (~90 s) and could be
 * replayed with the password. Verification now requires a strictly greater
 * step, enforced by an atomic UPDATE.
 */
class AddTwoFactorLastStep extends Migration {
    public function up(): void {
        try {
            $this->db->statement(
                "ALTER TABLE `users` ADD COLUMN `two_factor_last_step` BIGINT NULL AFTER `two_factor_enabled`"
            );
        } catch (\Throwable $e) {
            // Column may already exist on re-run.
        }
    }
}
