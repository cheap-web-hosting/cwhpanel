<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;
class AddIoLimitToPackages extends Migration {
    public function up(): void {
        // Per-account cgroup v2 I/O bandwidth cap (MB/s, 0 = unlimited),
        // rendered as IORead/WriteBandwidthMax on the root block device in the
        // account's systemd slice. Mirrors CloudLinux-style IO limits.
        try { $this->db->statement("ALTER TABLE `packages` ADD COLUMN `max_io_mbs` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `max_processes`"); } catch (\Throwable $e) {}
    }
}
