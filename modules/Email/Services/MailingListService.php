<?php
declare(strict_types=1);
namespace Modules\Email\Services;

/**
 * MailingListService — panel-managed mailing lists.
 *
 * A list is a named multi-destination alias (name@domain) expanded by postfix
 * from the virtual map (see MailService::sync). Posting policy:
 *  - open:    anyone may post;
 *  - members: only active members may post, enforced at end-of-DATA by the
 *    mail policy daemon (cwhpanel-mail-policy rejects outsiders with 550).
 */
class MailingListService
{
    private \App\Foundation\Database\Connection $db;

    public function __construct(\App\Foundation\Database\Connection $db)
    {
        $this->db = $db;
    }

    public const POSTING = ['open', 'members'];
    public const MAX_MEMBERS = 200;
    public const MAX_NAME = 64;

    public static function address(array $list, array $domain): string
    {
        return strtolower((string) $list['name']) . '@' . strtolower((string) $domain['domain']);
    }

    /** @return array<int,array> lists with member counts for a domain */
    public function forDomain(int $domainId): array
    {
        $lists = $this->db->select('SELECT * FROM `mailing_lists` WHERE `domain_id` = ? ORDER BY `name`', [$domainId]);
        foreach ($lists as &$l) {
            $row = $this->db->selectOne("SELECT COUNT(*) AS n FROM `mailing_list_members` WHERE `list_id` = ? AND `status` = 'active'", [(int) $l['id']]);
            $l['member_count'] = (int) ($row['n'] ?? 0);
        }
        unset($l);
        return $lists;
    }

    /** @return array<int,array> members of a list */
    public function members(int $listId): array
    {
        return $this->db->select("SELECT * FROM `mailing_list_members` WHERE `list_id` = ? ORDER BY `email`", [$listId]);
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9._-]{1,64}$/', strtolower($name));
    }

    /**
     * Create a list on an (already ownership-checked) mail domain row.
     * @throws \RuntimeException on invalid input or address collision
     */
    public function create(int $accountId, array $domain, string $name, string $description, string $posting): int
    {
        $name = strtolower(trim($name));
        if (!self::validName($name)) {
            throw new \RuntimeException('List name must be 1-64 chars: letters, digits, dot, underscore, dash.');
        }
        if (!in_array($posting, self::POSTING, true)) {
            throw new \RuntimeException('Invalid posting policy.');
        }
        $address = $name . '@' . strtolower((string) $domain['domain']);
        $this->assertFree((int) $domain['id'], $address);
        \Modules\Packages\Services\LimitService::requireHardCap($accountId, 'mailing_lists');
        return (int) $this->db->insert('mailing_lists', [
            'account_id' => $accountId, 'domain_id' => (int) $domain['id'], 'name' => $name,
            'description' => trim($description) !== '' ? trim($description) : null,
            'posting' => $posting, 'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $listId): void
    {
        $this->db->delete('mailing_list_members', '`list_id` = ?', [$listId]);
        $this->db->delete('mailing_lists', '`id` = ?', [$listId]);
    }

    public function setPosting(int $listId, string $posting): void
    {
        if (!in_array($posting, self::POSTING, true)) {
            throw new \RuntimeException('Invalid posting policy.');
        }
        $this->db->update('mailing_lists', ['posting' => $posting, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$listId]);
    }

    /**
     * @throws \RuntimeException
     *
     * @param bool $allowExternal when false (customer call path), members are
     *   restricted to the account's own mail domains — the panel is not a
     *   bulk relay to arbitrary third-party addresses under a customer's
     *   control. Admins may still add external members explicitly.
     */
    public function addMember(array $list, array $domain, string $email, bool $allowExternal = false): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Member must be a valid email address.');
        }
        if ($email === self::address($list, $domain)) {
            throw new \RuntimeException('A list cannot subscribe to itself (mail loop).');
        }
        if (!$allowExternal) {
            $memberDomain = strtolower((string) explode('@', $email, 2)[1]);
            $domains = $this->db->select('SELECT `domain` FROM `mail_domains` WHERE `account_id` = ? AND `status` = ?', [(int) $list['account_id'], 'active']);
            $owned = [];
            foreach ($domains as $row) {
                $owned[] = strtolower((string) $row['domain']);
            }
            if (!in_array($memberDomain, $owned, true)) {
                throw new \RuntimeException('Members must be addresses on your own mail domains (admin can add external members).');
            }
        }
        $n = $this->db->selectOne("SELECT COUNT(*) AS n FROM `mailing_list_members` WHERE `list_id` = ? AND `status` = 'active'", [(int) $list['id']]);
        if ((int) ($n['n'] ?? 0) >= self::MAX_MEMBERS) {
            throw new \RuntimeException('List is full (max ' . self::MAX_MEMBERS . ' members).');
        }
        if ($this->db->selectOne('SELECT `id` FROM `mailing_list_members` WHERE `list_id` = ? AND `email` = ?', [(int) $list['id'], $email])) {
            throw new \RuntimeException('Address is already subscribed.');
        }
        $this->db->insert('mailing_list_members', [
            'list_id' => (int) $list['id'], 'email' => $email, 'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function removeMember(int $listId, int $memberId): void
    {
        $this->db->delete('mailing_list_members', '`id` = ? AND `list_id` = ?', [$memberId, $listId]);
    }

    /**
     * A list address must not collide with a mailbox, forwarder source, or
     * another list on the same domain (postfix map keys must be unique).
     * @throws \RuntimeException
     */
    private function assertFree(int $domainId, string $address): void
    {
        if ($this->db->selectOne('SELECT `id` FROM `mailboxes` WHERE `email` = ?', [$address])) {
            throw new \RuntimeException('Address is already a mailbox.');
        }
        if ($this->db->selectOne('SELECT `id` FROM `forwarders` WHERE `domain_id` = ? AND `source` = ?', [$domainId, $address])) {
            throw new \RuntimeException('Address is already a forwarder.');
        }
        if ($this->db->selectOne('SELECT l.`id` FROM `mailing_lists` l JOIN `mail_domains` d ON d.`id` = l.`domain_id` WHERE l.`domain_id` = ? AND CONCAT(l.`name`, "@", d.`domain`) = ?', [$domainId, $address])) {
            throw new \RuntimeException('List already exists.');
        }
    }
}
