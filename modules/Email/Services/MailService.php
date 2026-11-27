<?php
declare(strict_types=1);
namespace Modules\Email\Services;

/**
 * Backs mailboxes with a real backend: postfix virtual-alias maps (inbound
 * routing) plus Dovecot virtual users (IMAP/POP3 + LMTP delivery).
 *
 * Passwords are stored as a Dovecot-compatible {CRYPT} bcrypt hash so they can
 * be written into the passdb file without ever storing plaintext in the panel DB.
 */
class MailService
{
    /**
     * Resolve the per-mailbox outbound hourly limit from the account's hosting
     * package. Falls back to the package default (50) when unset.
     */
    public static function mailboxHourlyLimit(int $accountId): int
    {
        $row = app('db')->selectOne(
            'SELECT p.`mailbox_max_out_per_hour` FROM `accounts` a
             LEFT JOIN `packages` p ON p.`id` = a.`package_id`
             WHERE a.`id` = ?',
            [$accountId]
        );

        if ($row !== null && isset($row['mailbox_max_out_per_hour'])) {
            return (int) $row['mailbox_max_out_per_hour'];
        }

        return 50;
    }

    /**
     * Per-mailbox size ceiling (MB) from the account's hosting package.
     * 0 = unlimited (Dovecot falls back to its default rule). Defaults to
     * 1024 (1 GB) when the package/column predates the limit.
     */
    public static function mailboxQuotaMax(int $accountId): int
    {
        try {
            $row = app('db')->selectOne(
                'SELECT p.`mailbox_quota_max_mb` FROM `accounts` a
                 LEFT JOIN `packages` p ON p.`id` = a.`package_id`
                 WHERE a.`id` = ?',
                [$accountId]
            );
        } catch (\Throwable $e) {
            return 1024;
        }

        if ($row !== null && isset($row['mailbox_quota_max_mb'])) {
            return max(0, (int) $row['mailbox_quota_max_mb']);
        }

        return 1024;
    }

    /**
     * Hash a plaintext password into a Dovecot {CRYPT} (bcrypt) value.
     */
    public static function hash(string $plaintext): string
    {
        // bcrypt (cost 10) salt of the shape "$2y$10$" + 22 base64 chars.
        $salt = '$2y$10$' . substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 22);
        return '{CRYPT}' . crypt($plaintext, $salt);
    }

    /**
     * Normalize a mailbox address to a full address on the given (already
     * ownership-checked) mail domain row: bare local parts get @domain
     * appended; full addresses must belong to the domain. Also guards
     * against mailbox/forwarder/list collisions, so every create path
     * writes a usable Dovecot user + postfix recipient.
     * @return array{0:?string,1:?string} [email, error]
     */
    public static function normalizeMailbox(array $domain, string $rawEmail): array
    {
        $input = strtolower(trim($rawEmail));
        $at = strtolower((string) $domain['domain']);
        if (str_contains($input, '@')) {
            $email = $input;
            if (substr($email, -strlen('@' . $at)) !== '@' . $at) {
                return [null, 'That address is not on this mail domain.'];
            }
        } else {
            $email = $input === '' ? '@' . $at : $input . '@' . $at;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [null, 'Mailbox must be a valid email address (or a local part on this domain).'];
        }
        $did = (int) $domain['id'];
        if (app('db')->selectOne("SELECT `id` FROM `mailboxes` WHERE `email`=?", [$email])) {
            return [null, 'A mailbox with that address already exists.'];
        }
        if (app('db')->selectOne("SELECT `id` FROM `forwarders` WHERE `domain_id`=? AND `source`=?", [$did, $email])) {
            return [null, 'That address is already a forwarder.'];
        }
        if (app('db')->selectOne('SELECT l.`id` FROM `mailing_lists` l JOIN `mail_domains` d ON d.`id` = l.`domain_id` WHERE l.`domain_id` = ? AND CONCAT(l.`name`, "@", d.`domain`) = ?', [$did, $email])) {
            return [null, 'That address is already a mailing list.'];
        }
        return [$email, null];
    }

    /**
     * Normalize a forwarder source to a full address on the given (already
     * ownership-checked) mail domain row: bare local parts get @domain
     * appended, full addresses pass through. Also validates the destination
     * and guards against mailbox/forwarder/list collisions on the domain, so
     * every create path writes a usable postfix map key.
     * @return array{0:?string,1:?string,2:?string} [source, destination, error]
     */
    public static function normalizeForwarder(array $domain, string $rawSource, string $rawDest): array
    {
        $local = strtolower(trim($rawSource));
        $at = strtolower(trim((string) ($domain['domain'] ?? '')));
        // A forwarder source becomes a key in the ONE global postfix virtual
        // map, so it must belong to the mail domain the caller proved ownership
        // of. Accepting a foreign full address let a tenant alias another
        // tenant's address (e.g. billing@victim.test) to their own mailbox —
        // cross-tenant mail interception — and grant themselves the right to
        // authenticate as that sender. normalizeMailbox() already enforced
        // this; forwarders did not.
        if (str_contains($local, '@')) {
            $source = $local;
            if ($at === '' || substr($source, -strlen('@' . $at)) !== '@' . $at) {
                return [null, null, 'That address is not on this mail domain.'];
            }
        } else {
            $source = $local . '@' . $at;
        }
        $dest = strtolower(trim($rawDest));
        if ($local === '' || !filter_var($source, FILTER_VALIDATE_EMAIL)) {
            return [null, null, 'Source must be a valid email address (or a local part on this domain).'];
        }
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            return [null, null, 'Destination must be a valid email address.'];
        }
        $did = (int) $domain['id'];
        if (app('db')->selectOne("SELECT `id` FROM `mailboxes` WHERE `email`=?", [$source])) {
            return [null, null, 'That address is already a mailbox.'];
        }
        // Global, not per-domain: the map key is global, so a duplicate on
        // another domain would shadow that tenant's own forwarder.
        if (app('db')->selectOne("SELECT `id` FROM `forwarders` WHERE `source`=?", [$source])) {
            return [null, null, 'That forwarder already exists.'];
        }
        if (app('db')->selectOne('SELECT l.`id` FROM `mailing_lists` l JOIN `mail_domains` d ON d.`id` = l.`domain_id` WHERE CONCAT(l.`name`, "@", d.`domain`) = ?', [$source])) {
            return [null, null, 'That address is already a mailing list.'];
        }
        return [$source, $dest, null];
    }

    private function accountUsername(int $accountId): ?string
    {
        $row = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `id` = ?', [$accountId]);
        return $row ? (string) $row['username'] : null;
    }

    /**
     * Current provisioning actor role (admin/customer), or null when unset
     * (CLI/worker/guest paths behave as admin scope: full sync).
     */
    private function actorRole(): ?string
    {
        try {
            $actor = app('provisioning')->actor();
            return isset($actor['role']) ? (string) $actor['role'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * In a customer-triggered sync, per-account writes are scoped to the
     * customer's own account (the global maps still cover everyone, and the
     * agent verifies them against the panel DB). Returns the scoped account
     * username, or null for admin/root (full sync).
     */
    private function scopeAccount(): ?string
    {
        if ($this->actorRole() !== 'customer') {
            return null;
        }
        try {
            $actor = app('provisioning')->actor();
            return isset($actor['account']) && $actor['account'] !== null
                ? (string) $actor['account']
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function localPart(string $email): string
    {
        return explode('@', $email, 2)[0];
    }

    /**
     * Rebuild the postfix virtual maps + Dovecot passdb/userdb + Maildirs from
     * the panel DB. Called after any mailbox/forwarder change.
     */
    public function sync(): bool
    {
        $mailboxes = app('db')->select("SELECT * FROM `mailboxes` WHERE `status` = 'active' ORDER BY `email`");
        $forwarders = app('db')->select('SELECT `source`,`destination`,`domain_id` FROM `forwarders` ORDER BY `source`');

        // 1. Ensure the mail stack is configured. These are one-time bootstrap
        // ops (postconf/dovecot template writes, milter/policy enables) — they
        // belong to install/admin flows, never to a customer-triggered sync.
        // (The agent refuses them for customer tickets; a customer request must
        // not re-run them.)
        if ($this->actorRole() !== 'customer') {
            $masterPassword = (string) env('MAIL_MASTER_PASSWORD', '');
            app('provisioning')->execute('mail.setup', ['master_password' => $masterPassword]);
            app('provisioning')->execute('mail.smtp.setup');
            app('provisioning')->execute('mail.policy.enable');
        }

        // Sync virtual mailbox domains (postfix local delivery).
        $domains = app('db')->select("SELECT `domain` FROM `mail_domains` WHERE `status` = 'active' ORDER BY `domain`");
        app('provisioning')->execute('mail.domains.sync', [
            'domains' => implode("\n", array_column($domains, 'domain')),
        ]);

        // 2. Postfix virtual aliases — forwarders plus per-domain catch-alls.
        // Mailboxes are delivered via LMTP against virtual_mailbox_domains
        // (no self-alias needed). A catch-all (@domain target) redirects mail
        // for non-existent addresses; domains without one bounce (default).
        $aliasLines = [];
        foreach ($forwarders as $f) {
            $aliasLines[] = $f['source'] . ' ' . $f['destination'];
        }
        // Mailing lists: one virtual-map key expanding (comma-separated, per
        // virtual(5)) to every active member. Empty lists get no line, so
        // posts to them bounce instead of vanishing.
        $lists = app('db')->select(
            "SELECT l.`id`, l.`name`, d.`domain` FROM `mailing_lists` l
              JOIN `mail_domains` d ON d.`id` = l.`domain_id`
              WHERE l.`status` = 'active' AND d.`status` = 'active' ORDER BY l.`id`"
        );
        foreach ($lists as $list) {
            $members = app('db')->select(
                "SELECT `email` FROM `mailing_list_members` WHERE `list_id` = ? AND `status` = 'active' ORDER BY `email`",
                [(int) $list['id']]
            );
            $emails = [];
            foreach ($members as $m) {
                $e = strtolower(trim((string) ($m['email'] ?? '')));
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $e;
                }
            }
            $emails = array_values(array_unique($emails));
            if ($emails === []) {
                continue;
            }
            $aliasLines[] = strtolower((string) $list['name']) . '@' . strtolower((string) $list['domain']) . ' ' . implode(',', $emails);
        }
        $catchallDomains = app('db')->select(
            "SELECT `domain`, `catchall` FROM `mail_domains` WHERE `status` = 'active' AND `catchall` IS NOT NULL AND `catchall` != '' ORDER BY `domain`"
        );
        foreach ($catchallDomains as $cd) {
            $target = strtolower(trim((string) ($cd['catchall'] ?? '')));
            if (filter_var($target, FILTER_VALIDATE_EMAIL)) {
                $aliasLines[] = '@' . strtolower((string) $cd['domain']) . ' ' . $target;
            } else {
                app('logger')->warning('Skipping invalid catch-all target for ' . $cd['domain']);
            }
        }
        if ($aliasLines === []) {
            // Never delete the map: a missing hash: file turns EVERY lookup
            // into a 451 temp-failure. An empty map misses cleanly instead.
            app('provisioning')->execute('mail.virtual.write', ['domain' => 'all', 'entries' => "# no aliases\n"]);
        } else {
            app('provisioning')->execute('mail.virtual.write', ['domain' => 'all', 'entries' => implode("\n", $aliasLines)]);
        }

        // 2b. Sender-login map (anti-spoofing): each mailbox may send as
        // itself; each forwarder source may be sent by any active mailbox of
        // the same mail domain (same owner). Enforced for SASL-authenticated
        // senders via reject_sender_login_mismatch; unauthenticated inbound
        // is unaffected. Always written, even when empty.
        $logins = [];
        $domainBoxes = [];
        foreach ($mailboxes as $mb) {
            $e = strtolower(trim((string) ($mb['email'] ?? '')));
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $logins[$e][] = $e;
            $domainBoxes[(int) ($mb['domain_id'] ?? 0)][] = $e;
        }
        foreach ($forwarders as $f) {
            $src = strtolower(trim((string) ($f['source'] ?? '')));
            if (!filter_var($src, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            foreach (array_unique($domainBoxes[(int) ($f['domain_id'] ?? 0)] ?? []) as $box) {
                $logins[$src][] = $box;
            }
        }
        $loginLines = [];
        foreach ($logins as $sender => $who) {
            $who = array_values(array_unique($who));
            if ($who !== []) {
                $loginLines[] = $sender . ' ' . implode(',', $who);
            }
        }
        $senderEntries = $loginLines === [] ? "# no sender logins\n" : implode("\n", $loginLines);
        app('provisioning')->execute('mail.sender.write', [
            'entries' => $senderEntries,
        ]);

        // Submission-only pcre map: catch-all deny so authenticated senders
        // cannot spoof an address with no owner. Inbound :25 keeps the hash map.
        try {
            app('provisioning')->execute('mail.sender.submission.write', [
                'entries' => $senderEntries,
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning('Submission sender map write failed: ' . $e->getMessage());
        }

        // 2b-ii. Per-account allowed envelope senders, consumed by the sendmail
        // wrapper: a hosting account's local submission (PHP mail(), cron,
        // shell) may only send as an address it owns. Written even when empty.
        $allowByAccount = [];
        foreach ($mailboxes as $mb) {
            $e = strtolower(trim((string) ($mb['email'] ?? '')));
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $allowByAccount[(int) ($mb['account_id'] ?? 0)][] = $e;
            }
        }
        foreach ($forwarders as $f) {
            $src = strtolower(trim((string) ($f['source'] ?? '')));
            if (filter_var($src, FILTER_VALIDATE_EMAIL)) {
                $allowByAccount[(int) ($f['account_id'] ?? 0)][] = $src;
            }
        }
        foreach ($allowByAccount as $acctId => $addrs) {
            $uname = $this->accountUsername($acctId);
            if ($uname === null) {
                continue;
            }
            // Customer-triggered sync only rewrites the customer's own
            // allowed-sender file; other accounts' files are unchanged by
            // this action (their content still rides the global maps).
            $scope = $this->scopeAccount();
            if ($scope !== null && $uname !== $scope) {
                continue;
            }
            try {
                app('provisioning')->execute('mail.senders.allow.write', [
                    'account' => $uname,
                    'senders' => implode("\n", array_values(array_unique($addrs))),
                ]);
            } catch (\Throwable $e) {
                app('logger')->warning('Allowed-sender write failed for ' . $uname . ': ' . $e->getMessage());
            }
        }

        // 2c. Per-account outbound transports (dedicated IPv6): every
        // mailbox and forwarder-source address of a v6 account selects its
        // smtp-<user> transport (v6-bound; v4 falls back to shared). Never
        // fatal to the mail sync — worst case is shared-IP outbound.
        try {
            $this->syncOutbound($mailboxes, $forwarders);
        } catch (\Throwable $e) {
            app('logger')->warning('Mail outbound sync failed: ' . $e->getMessage());
        }

        // 3. Dovecot virtual users (passdb + userdb) and Maildirs.
        $scope = $this->scopeAccount();
        $dovecotEntries = [];
        foreach ($mailboxes as $mb) {
            $username = $this->accountUsername((int) $mb['account_id']);
            if ($username === null) {
                continue;
            }
            $pw = $this->posixUidGid($username);
            $local = $this->localPart($mb['email']);
            $home = "/home/{$username}/mail/{$local}";

            // Customer-triggered sync: only ensure the customer's own
            // Maildirs (other accounts' maildirs are untouched by this
            // action; their userdb entries still ride the global map).
            if ($scope === null || $username === $scope) {
                app('provisioning')->execute('mail.maildir.create', [
                    'account' => $username,
                    'mailbox' => $local,
                ]);
            }

            $dovecotEntries[] = implode(' ', [
                $mb['email'],
                $mb['password'],
                $pw['uid'],
                $pw['gid'],
                $home,
                // Per-mailbox quota_rule for Dovecot (maildir++ enforced on
                // delivery). Clamped to >= 1 MB — a zero/empty value would
                // fail agent validation; unlimited is the plugin default.
                max(1, (int) ($mb['quota_mb'] ?? 100)),
            ]);
        }

        // Always write the passwd-file maps, even with zero mailboxes — like the
        // postfix maps, a MISSING file makes Dovecot fail every lookup.
        app('provisioning')->execute('mail.dovecot.sync', ['entries' => implode("\n", $dovecotEntries)]);

        return true;
    }

    /**
     * Rebuild sender-dependent outbound transports: mailbox + forwarder
     * addresses of IPv6 accounts select their smtp-<user> transport
     * (dedicated v6 source; v4 uses the shared default). Disabled feature
     * (or zero v6 accounts) writes an empty set — lookups miss cleanly
     * onto the default transport, never tempfail.
     */
    private function syncOutbound(array $mailboxes, array $forwarders): void
    {
        try {
            $enabled = app(\Modules\Network\Services\IpAllocatorService::class)->enabled();
        } catch (\Throwable $e) {
            $enabled = false;
        }
        if (!$enabled) {
            app('provisioning')->execute('mail.outbound.sync', ['senders' => "# IP management off\n", 'transports' => '']);
            return;
        }
        $v6accounts = [];
        foreach (app('db')->select("SELECT `id`, `username`, `ipv6_address` FROM `accounts` WHERE `status` = 'active'") as $a) {
            $v6 = app(\Modules\Network\Services\IpAllocatorService::class)->effectiveIpv6($a);
            $username = (string) $a['username'];
            // Transport names live in master.cf: keep the agent's charset
            // (one bad name must not fail the whole sync).
            if ($v6 === '' || filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                continue;
            }
            if (preg_match('/^[a-z0-9_-]{1,33}$/', $username) !== 1) {
                app('logger')->warning('Mail outbound: skipping account with non-transport-safe username ' . $username);
                continue;
            }
            $v6accounts[(int) $a['id']] = ['username' => $username, 'ipv6' => $v6];
        }
        if ($v6accounts === []) {
            app('provisioning')->execute('mail.outbound.sync', ['senders' => "# no dedicated IPs\n", 'transports' => '']);
            return;
        }
        // domain_id -> account (same ownership rule as sender_logins).
        $domainAccount = [];
        foreach (app('db')->select("SELECT `id`, `account_id` FROM `mail_domains` WHERE `status` = 'active'") as $d) {
            $domainAccount[(int) $d['id']] = (int) $d['account_id'];
        }
        $senders = [];
        $addSender = function (string $email, int $accountId) use (&$senders, $v6accounts): void {
            if (!isset($v6accounts[$accountId])) {
                return;
            }
            $email = strtolower(trim($email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return;
            }
            $senders[$email] = 'smtp-' . $v6accounts[$accountId]['username'] . ':';
        };
        foreach ($mailboxes as $mb) {
            if (($mb['status'] ?? 'active') !== 'active') {
                continue;
            }
            $accountId = isset($mb['account_id'])
                ? (int) $mb['account_id']
                : (int) ($domainAccount[(int) ($mb['domain_id'] ?? 0)] ?? 0);
            $addSender((string) ($mb['email'] ?? ''), $accountId);
        }
        foreach ($forwarders as $f) {
            $addSender((string) ($f['source'] ?? ''), (int) ($domainAccount[(int) ($f['domain_id'] ?? 0)] ?? 0));
        }
        ksort($senders);
        $senderLines = [];
        foreach ($senders as $email => $transport) {
            $senderLines[] = $email . ' ' . $transport;
        }
        $blocks = [];
        foreach ($v6accounts as $a) {
            $blocks[] = "smtp-{$a['username']} unix  -       -       n       -       -       smtp\n"
                . "  -o smtp_bind_address6={$a['ipv6']}\n"
                . "  -o syslog_name=postfix-{$a['username']}";
        }
        app('provisioning')->execute('mail.outbound.sync', [
            'senders' => $senderLines === [] ? "# no v6 senders\n" : implode("\n", $senderLines),
            'transports' => implode("\n", $blocks),
        ]);
    }

    private function posixUidGid(string $username): array
    {
        $pw = posix_getpwnam($username);
        if ($pw === false) {
            return ['uid' => '1000', 'gid' => '1000'];
        }
        return ['uid' => (string) $pw['uid'], 'gid' => (string) $pw['gid']];
    }
}