<?php
declare(strict_types=1);
namespace Modules\Domains\Migrations;

use App\Foundation\Database\Migration;

/**
 * Per-domain mail/DNS profile.
 *
 * Domain provisioning used to publish a fixed mail footprint (mail A/MX, SPF
 * "mx a -all", DMARC p=none, DKIM "default", webmail A) that customers could
 * not change and that re-provisioning overwrote. These columns let the domain
 * owner pick what is actually true for their domain:
 *
 *   mail_mode       'local'  = mail is hosted on this panel (default)
 *                   'external' = mail lives at another provider: no mail A/AAAA,
 *                                no MX, no DKIM (SPF only when mechanisms are given)
 *   spf_extra       extra SPF mechanisms, e.g. "include:_spf.google.com"
 *   dmarc_policy    none|quarantine|reject
 *   dmarc_rua       report address (default postmaster@<domain>)
 *   webmail_record  publish webmail.<domain> (default yes)
 *   dns_ttl         per-zone TTL (NULL = server default 3600)
 */
class AddMailDnsProfile extends Migration
{
    public function up(): void
    {
        $columns = [
            "ADD COLUMN IF NOT EXISTS `mail_mode` VARCHAR(16) NOT NULL DEFAULT 'local' AFTER `status`",
            "ADD COLUMN IF NOT EXISTS `spf_extra` VARCHAR(255) NULL DEFAULT NULL AFTER `mail_mode`",
            "ADD COLUMN IF NOT EXISTS `dmarc_policy` VARCHAR(16) NULL DEFAULT NULL AFTER `spf_extra`",
            "ADD COLUMN IF NOT EXISTS `dmarc_rua` VARCHAR(255) NULL DEFAULT NULL AFTER `dmarc_policy`",
            "ADD COLUMN IF NOT EXISTS `webmail_record` TINYINT(1) NOT NULL DEFAULT 1 AFTER `dmarc_rua`",
            "ADD COLUMN IF NOT EXISTS `dns_ttl` INT NULL DEFAULT NULL AFTER `webmail_record`",
        ];
        foreach ($columns as $clause) {
            try {
                $this->db->statement('ALTER TABLE `domains` ' . $clause);
            } catch (\Throwable $e) {
                // Column already present (older MariaDB without IF NOT EXISTS).
            }
        }
    }
}
