<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Migrations;
use App\Foundation\Database\Migration;

/**
 * Remove Microweber from the catalog: microweber.com/download serves HTML
 * (not a zip) and upstream distributes only via `composer create-project`
 * (zero release archives), which the installer cannot consume. No
 * installations ever referenced it.
 */
class RemoveMicroweberApp extends Migration {
    public function up(): void
    {
        try {
            $this->db->delete('ai_applications', '`slug` = ?', ['microweber']);
        } catch (\Throwable $e) {
        }
    }
}
