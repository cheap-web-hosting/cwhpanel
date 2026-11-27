<?php
declare(strict_types=1);
namespace Modules\Security\Migrations;
use App\Foundation\Database\Migration;

/**
 * Per-website ModSecurity rule exclusions (SecRuleRemoveById). One row per
 * disabled rule; the per-domain conf is regenerated from these rows.
 */
class CreateWafExclusionsTable extends Migration {
    public function up(): void {
        try {
            $this->createTable('website_waf_exclusions', function ($t) {
                $t->id();
                $t->unsignedBigInteger('website_id');
                $t->unsignedBigInteger('rule_id');
                $t->nullableString('reason', 191);
                $t->timestamp('created_at', true);
            });
        } catch (\Throwable $e) {
            // Table already exists (rerun-safe).
        }
        try {
            $this->db->statement('ALTER TABLE `website_waf_exclusions` ADD UNIQUE KEY `waf_excl_site_rule_unique` (`website_id`, `rule_id`)');
        } catch (\Throwable $e) {}
        try {
            $this->db->statement('ALTER TABLE `website_waf_exclusions` ADD INDEX `waf_excl_site_idx` (`website_id`)');
        } catch (\Throwable $e) {}
    }
}
