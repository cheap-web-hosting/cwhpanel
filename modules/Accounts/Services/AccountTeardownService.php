<?php

declare(strict_types=1);

namespace Modules\Accounts\Services;

/**
 * AccountTeardownService — removes every resource an account owns before the
 * system user is deleted.
 *
 * Previously termination only destroyed system resources (jail, masters,
 * slice, user) and left child DB rows + running apps + vhosts/DNS/mail
 * behind, so a re-created account reusing the username inherited the previous
 * tenant's data. This walks the account's resources, tears down the live
 * services (apps, worker, vhosts, DNS, SSL, mail) and deletes the rows.
 *
 * Every step is best-effort: a single failure is logged and does not abort
 * the rest of the teardown.
 */
class AccountTeardownService
{
    /** @return array{removed:array<string,int>, errors:array<int,string>} */
    public function purge(int $accountId, array $account): array
    {
        $username = (string) ($account['username'] ?? '');
        $db = app('db');
        $removed = [];
        $errors = [];

        $step = function (string $label, callable $fn) use (&$removed, &$errors, $username): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $errors[] = $label . ': ' . $e->getMessage();
                app('logger')->warning("Teardown {$label} failed for {$username}: " . $e->getMessage());
            }
        };

        // 1. Applications: stop running units first (needs the home/user).
        $apps = $db->select('SELECT * FROM `applications` WHERE `account_id` = ?', [$accountId]);
        foreach ($apps as $app) {
            $step('app.stop', function () use ($account, $app) {
                app(\Modules\Applications\Services\AppService::class)->stop($account, $app);
            });
        }
        $step('applications.delete', function () use ($db, $accountId) {
            $db->delete('applications', '`account_id` = ?', [$accountId]);
        });
        $removed['applications'] = count($apps);

        // 2. Websites + protected dirs + their nginx vhosts (dedup by label).
        $websites = $db->select('SELECT `id`, `label` FROM `websites` WHERE `account_id` = ?', [$accountId]);
        $labels = [];
        $websiteIds = [];
        foreach ($websites as $w) {
            $websiteIds[] = (int) $w['id'];
            if (($w['label'] ?? '') !== '') {
                $labels[(string) $w['label']] = true;
            }
        }
        if ($websiteIds !== []) {
            $ph = implode(',', array_fill(0, count($websiteIds), '?'));
            $step('protected_dirs.delete', function () use ($db, $ph, $websiteIds) {
                $dirs = $db->select("SELECT `id` FROM `website_protected_dirs` WHERE `website_id` IN ({$ph})", $websiteIds);
                foreach ($dirs as $d) {
                    $db->delete('website_dir_users', '`dir_id` = ?', [(int) $d['id']]);
                }
                $db->delete('website_protected_dirs', "`website_id` IN ({$ph})", $websiteIds);
            });
        }
        $step('websites.delete', function () use ($db, $accountId) {
            $db->delete('websites', '`account_id` = ?', [$accountId]);
        });
        foreach (array_keys($labels) as $label) {
            $step('vhost.delete', function () use ($label) {
                app(\Modules\Websites\Services\VhostWriter::class)->delete($label);
            });
        }
        $removed['websites'] = count($websites);

        // 3. Domains.
        $domains = $db->select('SELECT `id` FROM `domains` WHERE `account_id` = ?', [$accountId]);
        $step('domains.delete', function () use ($db, $accountId) {
            $db->delete('domains', '`account_id` = ?', [$accountId]);
        });
        $removed['domains'] = count($domains);

        // 4. DNS zones (records live in PowerDNS; zone delete removes them).
        $zones = $db->select('SELECT `id`, `name` FROM `dns_zones` WHERE `account_id` = ?', [$accountId]);
        foreach ($zones as $z) {
            $step('dns.deleteZone', function () use ($z) {
                app(\Modules\Dns\Services\DnsService::class)->deleteZone((string) $z['name']);
            });
        }
        $step('dns.zones.delete', function () use ($db, $accountId) {
            $db->delete('dns_zones', '`account_id` = ?', [$accountId]);
        });
        $removed['dns_zones'] = count($zones);

        // 5. Mail: mailboxes/forwarders/lists + domains, then one global sync
        //    so the postfix/dovecot maps drop them.
        $mailDomains = $db->select('SELECT `id` FROM `mail_domains` WHERE `account_id` = ?', [$accountId]);
        foreach ($mailDomains as $md) {
            $mid = (int) $md['id'];
            $step('mail.lists.delete', function () use ($db, $mid) {
                foreach ($db->select('SELECT `id` FROM `mailing_lists` WHERE `domain_id` = ?', [$mid]) as $l) {
                    app(\Modules\Email\Services\MailingListService::class)->delete((int) $l['id']);
                }
            });
            $step('mail.addresses.delete', function () use ($db, $mid) {
                $boxes = $db->select('SELECT `id` FROM `mailboxes` WHERE `domain_id` = ?', [$mid]);
                foreach ($boxes as $b) {
                    $boxId = (int) $b['id'];
                    $db->delete('mail_filters', '`mailbox_id` = ?', [$boxId]);
                    $db->delete('mail_autoresponders', '`mailbox_id` = ?', [$boxId]);
                }
                $db->delete('mailboxes', '`domain_id` = ?', [$mid]);
                $db->delete('forwarders', '`domain_id` = ?', [$mid]);
            });
        }
        $step('mail.domains.delete', function () use ($db, $accountId) {
            $db->delete('mail_domains', '`account_id` = ?', [$accountId]);
        });
        $step('mail.sync', function () {
            app(\Modules\Email\Services\MailService::class)->sync();
        });
        $removed['mail_domains'] = count($mailDomains);

        // 6. SSL certificates (agent removes the files + TLS blocks).
        $certs = $db->select('SELECT `domain_name` FROM `certificates` WHERE `account_id` = ?', [$accountId]);
        foreach ($certs as $c) {
            $step('ssl.remove', function () use ($c) {
                app(\Modules\Ssl\Services\SslService::class)->remove((string) $c['domain_name']);
            });
        }
        $step('certificates.delete', function () use ($db, $accountId) {
            $db->delete('certificates', '`account_id` = ?', [$accountId]);
        });
        $removed['certificates'] = count($certs);

        // 7. Cron jobs (+ per-job run state). The system crontab itself dies
        //    with the user in provisioner->terminate().
        $crons = $db->select('SELECT `id` FROM `cron_jobs` WHERE `account_id` = ?', [$accountId]);
        foreach ($crons as $j) {
            $step('cron.deleteJob', function () use ($accountId, $j) {
                app(\Modules\Cron\Services\CronService::class)->deleteJob($accountId, (int) $j['id']);
            });
        }
        $removed['cron_jobs'] = count($crons);

        // 8. Leftover backup schedules/remotes (archives stay as history).
        $step('backups.schedules.delete', function () use ($db, $accountId) {
            $db->delete('backup_schedules', '`account_id` = ?', [$accountId]);
            $db->delete('backup_remotes', '`account_id` = ?', [$accountId]);
        });

        // 9. Stop the per-account nginx worker (its conf/home dies with the
        //    user; the unit must not keep serving).
        $step('nginx.worker.stop', function () use ($username) {
            app('provisioning')->execute('nginx.worker.stop', ['account' => $username]);
        });

        // 9b. Tear down the app network namespace (L-14) if present.
        $step('netns.destroy', function () use ($account) {
            app(\Modules\Network\Services\AppNetnsService::class)->destroy($account);
        });

        // 10. Reset account lifecycle flags so a reclaim starts clean.
        $step('account.flags.reset', function () use ($db, $accountId) {
            $db->update('accounts', [
                'suspend_state' => null,
                'nginx_worker_enabled' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$accountId]);
        });

        return ['removed' => $removed, 'errors' => $errors];
    }
}
