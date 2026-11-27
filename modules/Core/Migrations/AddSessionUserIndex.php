<?php
declare(strict_types=1);
namespace Modules\Core\Migrations;

use App\Foundation\Database\Migration;
use App\Foundation\Session;

/**
 * Index sessions.user_id (the "active sessions" list filters on it) and
 * backfill it for rows written before Session::write() attributed them.
 * user_id stays NULL for logged-out rows by design.
 */
class AddSessionUserIndex extends Migration {
    public function up(): void {
        try { $this->db->statement('ALTER TABLE `sessions` ADD INDEX `user_id` (`user_id`)'); } catch (\Throwable $e) {}
        try {
            $rows = $this->db->select('SELECT `id`, `payload` FROM `sessions` WHERE `user_id` IS NULL');
            foreach ($rows as $row) {
                $userId = Session::payloadUserId((string) ($row['payload'] ?? ''));
                if ($userId !== null) {
                    $this->db->update('sessions', ['user_id' => $userId], '`id` = ?', [$row['id']]);
                }
            }
        } catch (\Throwable $e) {}
    }
}
