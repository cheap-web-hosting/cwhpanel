<?php
declare(strict_types=1);
namespace Modules\Websites\Migrations;
use App\Foundation\Database\Migration;

/**
 * Per-directory PHP overrides: a subdirectory of a site can run a different
 * PHP version (nginx location → version socket) and/or locked per-dir
 * options (fastcgi PHP_ADMIN_VALUE). NULL version/settings inherit the site.
 */
class AddWebsitePhpDirs extends Migration {
    public function up(): void {
        try {
            $this->createTable('website_php_dirs', function ($t) {
                $t->id();
                $t->unsignedBigInteger('website_id');
                $t->string('dir', 255);
                $t->nullableString('php_version', 16);
                $t->nullableText('settings');
                $t->timestamps();
                $t->index('website_id');
            });
        } catch (\Throwable $e) {}
        try { $this->db->statement('ALTER TABLE `website_php_dirs` ADD UNIQUE KEY `site_dir` (`website_id`, `dir`)'); } catch (\Throwable $e) {}
    }
}
