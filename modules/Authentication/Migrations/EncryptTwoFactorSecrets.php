<?php

declare(strict_types=1);

namespace Modules\Authentication\Migrations;

use App\Foundation\Database\Migration;
use App\Foundation\Security\SecretBox;

/**
 * Seal existing TOTP seeds at rest (audit #14).
 *
 * `two_factor_secret` was created as VARCHAR(64) for a base32 seed. The sealed
 * form (nonce + MAC + ciphertext, base64, version prefix) is ~103 chars, so the
 * column is widened first, then every plaintext row is encrypted in place.
 */
class EncryptTwoFactorSecrets extends Migration
{
    public function up(): void
    {
        $this->db->statement('ALTER TABLE `users` MODIFY `two_factor_secret` VARCHAR(255) NULL');

        $rows = $this->db->select(
            "SELECT `id`, `two_factor_secret` FROM `users`
              WHERE `two_factor_secret` IS NOT NULL AND `two_factor_secret` <> ''"
        );
        foreach ($rows as $row) {
            $stored = (string) $row['two_factor_secret'];
            if (SecretBox::isEncrypted($stored)) {
                continue;
            }
            $this->db->update(
                'users',
                ['two_factor_secret' => SecretBox::encrypt($stored)],
                '`id` = ?',
                [(int) $row['id']]
            );
        }
    }
}
