<?php
declare(strict_types=1);
namespace Modules\Security\Migrations;
use App\Foundation\Database\Migration;
class CreateSecuritySettingsTable extends Migration {
    public function up(): void {
        $this->createTable('security_settings', function ($t) {
            $t->id(); $t->unsignedBigInteger('account_id'); $t->unsignedBigInteger('website_id'); $t->boolean('hotlink_protection'); $t->boolean('waf_enabled'); $t->string('waf_mode', 16); $t->timestamp('updated_at', true);
        });
        $this->db->statement('ALTER TABLE `security_settings` ADD UNIQUE KEY `security_settings_account_website_unique` (`account_id`, `website_id`)');
    }
}