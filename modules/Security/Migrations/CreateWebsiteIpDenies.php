<?php
declare(strict_types=1);

namespace Modules\Security\Migrations;

use App\Foundation\Database\Migration;

/**
 * Per-website IP denies (nginx `deny`).
 *
 * Customer IP blocks must not touch the host firewall: a firewalld rich rule
 * drops the address for every tenant. Denies are emitted into the site's
 * front-door vhost instead, so a customer can only block traffic to their own
 * websites. Admin blocks may target the whole host (`firewall_rules` +
 * firewalld) or a hand-picked set of websites (rows here, source `admin`).
 */
class CreateWebsiteIpDenies extends Migration
{
    public function up(): void
    {
        $exists = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `information_schema`.`tables`
              WHERE `table_schema` = DATABASE() AND `table_name` = 'website_ip_denies'"
        );
        if ($exists > 0) {
            return;
        }

        $this->createTable('website_ip_denies', function ($t) {
            $t->id();
            $t->unsignedBigInteger('website_id');
            $t->string('ip_address', 45);
            $t->string('source', 16); // customer | admin
            $t->nullableBigInteger('account_id');
            $t->nullableBigInteger('user_id');
            $t->nullableString('reason', 255);
            $t->timestamp('created_at', true);
            $t->index('website_id');
            $t->index('ip_address');
        });

        // One row per (site, IP): both sources must converge on the same key.
        try {
            $this->db->statement(
                'ALTER TABLE `website_ip_denies` ADD UNIQUE KEY `website_ip` (`website_id`,`ip_address`)'
            );
        } catch (\Throwable $e) {
            // Already present (re-run) — nothing to do.
        }
    }
}
