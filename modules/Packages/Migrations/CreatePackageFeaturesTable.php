<?php
declare(strict_types=1);
namespace Modules\Packages\Migrations;
use App\Foundation\Database\Migration;
class CreatePackageFeaturesTable extends Migration {
    public function up(): void {
        $this->createTable('package_features', function ($t) {
            $t->id();
            $t->unsignedBigInteger('package_id');
            $t->string('feature', 64);
            $t->index('package_id');
        });
        try {
            $this->db->statement('ALTER TABLE `package_features` ADD UNIQUE KEY `uq_package_feature` (`package_id`, `feature`)');
        } catch (\Throwable $e) {}
        // Backfill: every existing package keeps every feature, so current
        // behavior is unchanged until an admin edits the package.
        try {
            $packages = $this->db->select('SELECT `id` FROM `packages`');
            foreach ($packages as $p) {
                foreach (\Modules\Packages\Services\FeatureService::keys() as $key) {
                    try {
                        $this->db->insert('package_features', [
                            'package_id' => (int) $p['id'],
                            'feature' => $key,
                        ]);
                    } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {}
    }
}
