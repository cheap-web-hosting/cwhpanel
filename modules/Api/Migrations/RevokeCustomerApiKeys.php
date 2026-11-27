<?php
declare(strict_types=1);
namespace Modules\Api\Migrations;
use App\Foundation\Database\Migration;

/**
 * API access is administrator-only: customer-issued keys are revoked so no
 * non-admin credential can call /api/v1 after the customer UI is removed.
 */
class RevokeCustomerApiKeys extends Migration {
    public function up(): void {
        try {
            $this->db->statement(
                "UPDATE `api_keys` k JOIN `users` u ON u.`id` = k.`user_id`
                 SET k.`status` = 'revoked', k.`updated_at` = ?
                 WHERE u.`is_admin` = 0 AND k.`status` = 'active'",
                [date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            // Table/columns missing on a pre-API install: nothing to revoke.
        }
    }
}
