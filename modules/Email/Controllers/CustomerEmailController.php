<?php
declare(strict_types=1);
namespace Modules\Email\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Email\Services\MailService;
use Modules\Email\Services\MailingListService;

class CustomerEmailController {
    /**
     * Reconcile the mail maps/postfix with the DB after a mutation. The DB
     * row is already committed; a sync failure must never become a 500
     * (the change happened) — surface it as a visible error flash instead.
     */
    private function syncMail(): void
    {
        try {
            app(MailService::class)->sync();
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Mail configuration sync failed: ' . $e->getMessage());
        }
    }
    private function accountId(): ?int {
        $user = app('auth')->user(); if (!$user) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id`=?',[$user->id]);
        return $row ? (int)$row['id'] : null;
    }
    /** Load a mail domain owned by the current account (IDOR-safe). */
    private function ownedDomain(int $id): ?array {
        $accountId = $this->accountId(); if (!$accountId) return null;
        return app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=? AND `account_id`=?",[$id,$accountId]) ?: null;
    }
    public function index(): Response {
        // The Email area is one page per feature; the bare URL lands on mailboxes.
        return Response::redirect('/panel/email/mailboxes');
    }
    /** @return array<int,array> mail domains owned by the account */
    private function mailDomainRows(int $accountId): array {
        return app('db')->select("SELECT * FROM `mail_domains` WHERE `account_id`=? ORDER BY `domain`",[$accountId]);
    }
    /** @return array<int,int> member counts keyed by mailing-list id */
    public function mailboxes(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        // Domains the customer can create email accounts on: their hosting domains.
        $hostDomains = app('db')->select("SELECT `name` FROM `domains` WHERE `account_id`=? AND `status`='active' ORDER BY `name`",[$accountId]);
        $mailboxes = app('db')->select(
            "SELECT mb.*, d.`domain` FROM `mailboxes` mb LEFT JOIN `mail_domains` d ON d.`id`=mb.`domain_id` WHERE mb.`account_id`=? ORDER BY mb.`email`",
            [$accountId]
        );
        $content = view('customer/email/mailboxes', ['title'=>'Mailboxes','hostDomains'=>$hostDomains,'mailboxes'=>$mailboxes,'quotaMax'=>MailService::mailboxQuotaMax($accountId)], 'layouts/customer');
        return new Response((string) $content);
    }
    public function forwarders(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $forwarders = app('db')->select(
            "SELECT f.*, d.`domain` FROM `forwarders` f JOIN `mail_domains` d ON d.`id`=f.`domain_id` WHERE f.`account_id`=? ORDER BY f.`source`",
            [$accountId]
        );
        $content = view('customer/email/forwarders', ['title'=>'Forwarders','forwarders'=>$forwarders,'domainOptions'=>$this->domainOptions($accountId)], 'layouts/customer');
        return new Response((string) $content);
    }
    public function catchall(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $domains = $this->mailDomainRows($accountId);
        $content = view('customer/email/catchall', ['title'=>'Catch-all addresses','domains'=>$domains], 'layouts/customer');
        return new Response((string) $content);
    }
    public function lists(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $lists = app('db')->select(
            "SELECT l.*, d.`domain` FROM `mailing_lists` l JOIN `mail_domains` d ON d.`id`=l.`domain_id` WHERE l.`account_id`=? ORDER BY l.`name`",
            [$accountId]
        );
        // One batched query for ALL members (previously a query per list).
        $members = $this->memberMap($lists);
        $counts = [];
        foreach ($lists as $l) {
            $counts[(int) $l['id']] = count($members[(int) $l['id']] ?? []);
        }
        $content = view('customer/email/lists', ['title'=>'Mailing lists','lists'=>$lists,'listCounts'=>$counts,'listMembers'=>$members,'domainOptions'=>$this->domainOptions($accountId)], 'layouts/customer');
        return new Response((string) $content);
    }
    /** @return array<int,array> members keyed by mailing-list id (one batched query) */
    private function memberMap(array $lists): array {
        if ($lists === []) {
            return [];
        }
        $ids = array_map('intval', array_column($lists, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = app('db')->select(
            "SELECT * FROM `mailing_list_members` WHERE `list_id` IN ({$placeholders}) ORDER BY `email`",
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['list_id']][] = $r;
        }
        return $out;
    }
    /** Domain choices for the global create forms: mail-enabled domains plus
     * hosted domains whose mail row is auto-created on first use (same as the
     * mailbox form). @return array<int,array{name:string,mail:bool}> */
    private function domainOptions(int $accountId): array {
        $out = [];
        foreach ($this->mailDomainRows($accountId) as $m) {
            $out[] = ['name' => (string) $m['domain'], 'mail' => true];
        }
        $have = array_column($out, 'name');
        $hosted = app('db')->select("SELECT `name` FROM `domains` WHERE `account_id`=? AND `status`='active' ORDER BY `name`", [$accountId]);
        foreach ($hosted as $h) {
            if (!in_array($h['name'], $have, true)) {
                $out[] = ['name' => (string) $h['name'], 'mail' => false];
            }
        }
        return $out;
    }
    /** Resolve an owned mail domain by name, auto-creating the row for an
     * owned hosting domain (mirrors storeAccount). @return array{0:?array,1:?Response} [domain, errorRedirect] */
    private function mailDomainOrCreate(int $accountId, string $domain, string $back): array {
        $domain = strtolower(trim($domain));
        $mailDomain = app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `domain`=? AND `account_id`=?", [$domain, $accountId]);
        if ($mailDomain) return [$mailDomain, null];
        $owned = app('db')->selectOne("SELECT `id` FROM `domains` WHERE `name`=? AND `account_id`=?", [$domain, $accountId]);
        if (!$owned) {
            app('session')->flash('error', 'You do not host that domain.');
            return [null, Response::redirect($back)];
        }
        $did = app('db')->insert('mail_domains', ['domain' => $domain, 'account_id' => $accountId, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return [app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=?", [$did]) ?: ['id' => $did, 'domain' => $domain, 'account_id' => $accountId], null];
    }
    /** Global create handler for POST /panel/email/forwarders. */
    public function storeForwarder(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel/email/forwarders');
        try { $data = $request->validate(['domain' => 'required|max:255', 'source' => 'required|max:255', 'destination' => 'required|max:255']); }
        catch (ValidationException $e) { app('session')->flash('error', 'Please fill in all required fields.'); return Response::redirect('/panel/email/forwarders'); }
        [$mailDomain, $deny] = $this->mailDomainOrCreate($accountId, (string) $data['domain'], '/panel/email/forwarders');
        if ($deny !== null) return $deny;
        [$source, $dest, $error] = MailService::normalizeForwarder($mailDomain, (string) $data['source'], (string) $data['destination']);
        if ($error !== null) { app('session')->flash('error', $error); return Response::redirect('/panel/email/forwarders'); }
        \Modules\Packages\Services\LimitService::requireHardCap($accountId, 'forwarders');
        app('db')->insert('forwarders', ['account_id' => $accountId, 'domain_id' => (int) $mailDomain['id'], 'source' => $source, 'destination' => $dest, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->syncMail();
        app('session')->flash('success', 'Forwarder added: ' . $source);
        return Response::redirect('/panel/email/forwarders');
    }
    /** Global create handler for POST /panel/email/lists. */
    public function storeList(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel/email/lists');
        try { $data = $request->validate(['domain' => 'required|max:255', 'name' => 'required|max:64', 'posting' => 'max:16']); }
        catch (ValidationException $e) { app('session')->flash('error', 'Please fill in all required fields.'); return Response::redirect('/panel/email/lists'); }
        [$mailDomain, $deny] = $this->mailDomainOrCreate($accountId, (string) $data['domain'], '/panel/email/lists');
        if ($deny !== null) return $deny;
        try {
            $lid = app(MailingListService::class)->create($accountId, $mailDomain,
                (string) $data['name'], '', (string) ($data['posting'] ?? 'members'));
        } catch (\RuntimeException $e) { app('session')->flash('error', $e->getMessage()); return Response::redirect('/panel/email/lists'); }
        try {
            $this->syncMail();
            audit('email.list.created', 'mailing_list', $lid);
            app('session')->flash('success', 'Mailing list added.');
        } catch (\Throwable $e) { app('session')->flash('error', 'List saved but mail sync failed: ' . $e->getMessage()); }
        return Response::redirect('/panel/email/lists');
    }
    public function storeAccount(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel/email/mailboxes');
        try { $data = $request->validate(['domain'=>'required|max:255','local'=>'required|max:64','password'=>'required|min:8','quota_mb'=>'integer']); }
        catch(ValidationException $e) { app('session')->flash('error','Please fill in all required fields.'); return Response::redirect('/panel/email/mailboxes'); }
        $domain = strtolower((string)$data['domain']);
        $local = strtolower((string)$data['local']);

        // Ensure a mail domain exists for this domain (auto-create if needed —
        // same helper as the forwarder/list forms).
        [$mailDomain, $deny] = $this->mailDomainOrCreate($accountId, $domain, '/panel/email/mailboxes');
        if ($deny !== null) return $deny;
        $did = (int) $mailDomain['id'];

        [$email, $error] = MailService::normalizeMailbox(['id' => $did, 'domain' => $domain], $local);
        if ($error !== null) { app('session')->flash('error', $error); return Response::redirect('/panel/email/mailboxes'); }

        try {
            \Modules\Packages\Services\LimitService::require($accountId, 'mailboxes');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/email/mailboxes');
        }
        [$quota, $deny] = $this->quotaOrDeny($accountId, $data['quota_mb'] ?? 100, '/panel/email/mailboxes');
        if ($deny !== null) return $deny;

        app('db')->insert('mailboxes', [
            'account_id'=>$accountId,'domain_id'=>$did,'email'=>$email,
            'password'=>MailService::hash((string)$data['password']),
            'quota_mb'=>$quota,
            'max_out_per_hour'=>MailService::mailboxHourlyLimit($accountId),
            'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),
        ]);
        $this->syncMail();
        app('session')->flash('success','Email account created: '.$email);
        return Response::redirect('/panel/email/mailboxes');
    }
    /** Redirect the removed "add mail domain" path. */
    public function createRedirect(): Response {
        return Response::redirect('/panel/email');
    }
    public function showDomain(int $id): Response {
        $accountId = $this->accountId();
        $domain = app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=? AND `account_id`=?",[$id,$accountId]);
        if (!$domain) return Response::redirect('/panel/email');
        $mailboxes = app('db')->select("SELECT * FROM `mailboxes` WHERE `domain_id`=? ORDER BY `email`",[$id]);
        $forwarders = app('db')->select("SELECT * FROM `forwarders` WHERE `domain_id`=? ORDER BY `source`",[$id]);
        $content = view('customer/email/show', ['title'=>'Mail: '.$domain['domain'],'domain'=>$domain,'mailboxes'=>$mailboxes,'forwarders'=>$forwarders,'lists'=>app(MailingListService::class)->forDomain($id),'listMembers'=>self::listMembers($id),'quotaMax'=>MailService::mailboxQuotaMax($accountId)], 'layouts/customer');
        return new Response((string) $content);
    }
    /** @return array<int,array> members keyed by list id */
    private static function listMembers(int $domainId): array {
        $out = [];
        foreach (app(MailingListService::class)->forDomain($domainId) as $l) {
            $out[(int)$l['id']] = app(MailingListService::class)->members((int)$l['id']);
        }
        return $out;
    }
    public function addMailbox(int $id, Request $request): Response {
        $domain = $this->ownedDomain($id);
        if (!$domain) return Response::redirect('/panel/email');
        try { $data = $request->validate(['email'=>'required|max:255','password'=>'required|min:8','quota_mb'=>'integer']); }
        catch(ValidationException $e) { foreach($e->errors() as $f=>$ms) foreach($ms as $m) app('session')->pushError($f,$m); return Response::redirect('/panel/email/domains/'.$id); }
        [$email, $error] = MailService::normalizeMailbox($domain, (string) $data['email']);
        if ($error !== null) { app('session')->flash('error', $error); return Response::redirect('/panel/email/domains/'.$id); }
        try {
            \Modules\Packages\Services\LimitService::require((int) $domain['account_id'], 'mailboxes');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/email/domains/'.$id);
        }
        [$quota, $deny] = $this->quotaOrDeny((int) $domain['account_id'], $data['quota_mb'] ?? 100, '/panel/email/domains/' . $id);
        if ($deny !== null) return $deny;
        try {
            app('db')->insert('mailboxes', ['account_id'=>$domain['account_id'],'domain_id'=>$id,'email'=>$email,'password'=>MailService::hash($data['password']),'quota_mb'=>$quota,'max_out_per_hour'=>MailService::mailboxHourlyLimit((int)$domain['account_id']),'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) { app('session')->flash('error','A mailbox with that address already exists.'); }
            else { app('session')->flash('error','Could not create mailbox.'); }
            return Response::redirect('/panel/email/domains/'.$id);
        }
        $this->syncMail();
        app('session')->flash('success','Mailbox added.');
        return Response::redirect('/panel/email/domains/'.$id);
    }
    public function deleteMailbox(int $id, int $mid): Response {
        $domain = $this->ownedDomain($id);
        if (!$domain) return Response::redirect('/panel/email');
        app('db')->delete('mailboxes','`id`=? AND `domain_id`=?',[$mid,$id]);
        $this->syncMail();
        app('session')->flash('success','Mailbox deleted.');
        return Response::redirect('/panel/email/domains/'.$id);
    }
    public function changePassword(int $id, int $mid, Request $request): Response {
        $domain = $this->ownedDomain($id);
        if (!$domain) return Response::redirect('/panel/email');
        try { $data = $request->validate(['password'=>'required|min:8']); }
        catch(ValidationException $e) { app('session')->flash('error','Invalid password.'); return Response::redirect('/panel/email/domains/'.$id); }
        app('db')->update('mailboxes', ['password'=>MailService::hash($data['password']),'updated_at'=>date('Y-m-d H:i:s')], '`id`=? AND `domain_id`=?',[$mid,$id]);
        $this->syncMail();
        app('session')->flash('success','Password changed.');
        return Response::redirect('/panel/email/domains/'.$id);
    }
    public function addForwarder(int $id, Request $request): Response {
        $domain = $this->ownedDomain($id);
        if (!$domain) return Response::redirect('/panel/email');
        try { $data = $request->validate(['source'=>'required|max:255','destination'=>'required|max:255']); }
        catch(ValidationException $e) { foreach($e->errors() as $f=>$ms) foreach($ms as $m) app('session')->pushError($f,$m); return Response::redirect('/panel/email/domains/'.$id); }
        [$source, $dest, $error] = MailService::normalizeForwarder($domain, (string) $data['source'], (string) $data['destination']);
        if ($error !== null) { app('session')->flash('error', $error); return Response::redirect('/panel/email/domains/'.$id); }
        \Modules\Packages\Services\LimitService::requireHardCap((int) $domain['account_id'], 'forwarders');
        app('db')->insert('forwarders', ['account_id'=>$domain['account_id'],'domain_id'=>$id,'source'=>$source,'destination'=>$dest,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        $this->syncMail();
        app('session')->flash('success','Forwarder added: '.$source);
        return Response::redirect('/panel/email/domains/'.$id);
    }
    /** Callers on the global Email page pass return_to=/panel/email#anchor
     * to land back on their section; domain-page callers omit it. Only
     * same-area paths are honored (open-redirect safe). */
    private function backTo(Request $request, int $id, string $anchor = ''): string
    {
        $rt = trim((string) $request->input('return_to', ''));
        if ($rt !== '' && str_starts_with($rt, '/panel/email') && !str_contains($rt, '://') && strlen($rt) < 200) {
            return $rt;
        }
        return '/panel/email/domains/' . $id . ($anchor !== '' ? '#' . $anchor : '');
    }
    /** Validate a requested mailbox quota against the package ceiling.
     * @return array{0:int,1:?Response} [quota_mb, errorRedirect] */
    private function quotaOrDeny(int $accountId, $raw, string $back): array
    {
        $quota = max(1, (int) ($raw ?? 100));
        $max = MailService::mailboxQuotaMax($accountId);
        if ($max > 0 && $quota > $max) {
            app('session')->flash('error', "Mailbox size may not exceed {$max} MB on this hosting package.");
            return [0, Response::redirect($back)];
        }
        return [$quota, null];
    }
    public function deleteForwarder(int $id, int $fid, Request $request): Response {
        $domain = $this->ownedDomain($id);
        if (!$domain) return Response::redirect('/panel/email');
        app('db')->delete('forwarders','`id`=? AND `domain_id`=?',[$fid,$id]);
        $this->syncMail();
        app('session')->flash('success','Forwarder deleted.');
        return Response::redirect($this->backTo($request, $id, 'forwarders'));
    }
    private function ownedList(int $id, int $lid): ?array {
        if (!$this->ownedDomain($id)) return null;
        return app('db')->selectOne('SELECT * FROM `mailing_lists` WHERE `id`=? AND `domain_id`=?',[$lid,$id]) ?: null;
    }
    public function addList(int $id, Request $request): Response {
        $domain = $this->ownedDomain($id);
        if (!$domain) return Response::redirect('/panel/email');
        try {
            $lid = app(MailingListService::class)->create((int)$domain['account_id'], $domain,
                (string)$request->input('name',''), (string)$request->input('description',''), (string)$request->input('posting','members'));
        } catch (\RuntimeException $e) { app('session')->flash('error',$e->getMessage()); return Response::redirect('/panel/email/domains/'.$id); }
        try {
            $this->syncMail();
            audit('email.list.created','mailing_list',$lid);
            app('session')->flash('success','Mailing list added.');
        } catch (\Throwable $e) { app('session')->flash('error','List saved but mail sync failed: '.$e->getMessage()); }
        return Response::redirect('/panel/email/domains/'.$id);
    }
    public function deleteList(int $id, int $lid, Request $request): Response {
        if (!$this->ownedList($id,$lid)) return Response::redirect('/panel/email/domains/'.$id);
        app(MailingListService::class)->delete($lid);
        $this->syncMail();
        audit('email.list.deleted','mailing_list',$lid);
        app('session')->flash('success','Mailing list deleted.');
        return Response::redirect($this->backTo($request, $id, 'mailing-lists'));
    }
    public function setListPosting(int $id, int $lid, Request $request): Response {
        if (!$this->ownedList($id,$lid)) return Response::redirect('/panel/email/domains/'.$id);
        try {
            app(MailingListService::class)->setPosting($lid, (string)$request->input('posting','members'));
            $this->syncMail();
            app('session')->flash('success','Posting policy updated.');
        } catch (\RuntimeException $e) { app('session')->flash('error',$e->getMessage()); }
        return Response::redirect($this->backTo($request, $id, 'mailing-lists'));
    }
    public function addListMember(int $id, int $lid, Request $request): Response {
        $domain = $this->ownedDomain($id);
        $list = $domain ? $this->ownedList($id,$lid) : null;
        if (!$domain || !$list) return Response::redirect('/panel/email/domains/'.$id);
        try {
            app(MailingListService::class)->addMember($list, $domain, (string)$request->input('email',''), false);
            $this->syncMail();
            app('session')->flash('success','Member subscribed.');
        } catch (\RuntimeException $e) { app('session')->flash('error',$e->getMessage()); }
        return Response::redirect($this->backTo($request, $id, 'mailing-lists'));
    }
    public function removeListMember(int $id, int $lid, int $mid, Request $request): Response {
        if (!$this->ownedList($id,$lid)) return Response::redirect('/panel/email/domains/'.$id);
        app(MailingListService::class)->removeMember($lid,$mid);
        $this->syncMail();
        app('session')->flash('success','Member removed.');
        return Response::redirect($this->backTo($request, $id, 'mailing-lists'));
    }
    public function setCatchall(int $id, Request $request): Response {
        $domain = $this->ownedDomain($id);
        if (!$domain) return Response::redirect('/panel/email');
        $target = strtolower(trim((string) $request->input('catchall', '')));
        if ($target !== '' && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            app('session')->flash('error','Catch-all must be a valid email address (or empty to bounce unknown recipients).');
            return Response::redirect('/panel/email/domains/'.$id);
        }
        app('db')->update('mail_domains', ['catchall' => $target !== '' ? $target : null], '`id`=?', [$id]);
        try {
            $this->syncMail();
            app('session')->flash('success', $target !== '' ? 'Catch-all address updated.' : 'Catch-all cleared; unknown recipients will bounce.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Catch-all saved but mail sync failed: ' . $e->getMessage());
        }
        return Response::redirect($this->backTo($request, $id, 'catch-all'));
    }

    private function ownedMailbox(int $mid): ?array {
        $accountId = $this->accountId(); if (!$accountId) return null;
        return app('db')->selectOne("SELECT m.*, d.`domain` FROM `mailboxes` m LEFT JOIN `mail_domains` d ON d.`id`=m.`domain_id` WHERE m.`id`=? AND m.`account_id`=?", [$mid, $accountId]) ?: null;
    }

    public function mailboxFilters(int $mid): Response {
        $mb = $this->ownedMailbox($mid);
        if (!$mb) return Response::redirect('/panel/email');
        $state = app(\Modules\Email\Services\SieveService::class)->forMailbox($mid);
        $content = view('customer/email/filters', [
            'title' => 'Filters & Autoresponder: ' . $mb['email'],
            'mailbox' => $mb,
            'auto' => $state['auto'],
            'filters' => $state['filters'],
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function mailboxFiltersStore(int $mid, Request $request): Response {
        $mb = $this->ownedMailbox($mid);
        if (!$mb) return Response::redirect('/panel/email');
        try {
            $data = $request->validate([
                'subject' => 'max:255',
                'body' => 'max:8000',
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) app('session')->pushError($field, $msg);
            }
            return Response::redirect('/panel/email/mailbox/' . $mid . '/filters');
        }
        $enabled = $request->input('enabled') ? 1 : 0;
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($enabled && ($subject === '' || $body === '')) {
            app('session')->flash('error', 'Subject and message are required to enable the autoresponder.');
            return Response::redirect('/panel/email/mailbox/' . $mid . '/filters');
        }
        $existing = app('db')->selectOne('SELECT `id` FROM `mail_autoresponders` WHERE `mailbox_id` = ?', [$mid]);
        $row = ['enabled' => $enabled, 'subject' => $subject, 'body' => $body, 'updated_at' => date('Y-m-d H:i:s')];
        if ($existing) {
            app('db')->update('mail_autoresponders', $row, '`id` = ?', [(int) $existing['id']]);
        } else {
            $row['mailbox_id'] = $mid;
            $row['created_at'] = date('Y-m-d H:i:s');
            app('db')->insert('mail_autoresponders', $row);
        }
        try {
            app(\Modules\Email\Services\SieveService::class)->sync($mid);
            app('session')->flash('success', 'Autoresponder updated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Autoresponder saved but delivery update failed: ' . $e->getMessage());
        }
        return Response::redirect('/panel/email/mailbox/' . $mid . '/filters');
    }

    public function mailboxFilterAdd(int $mid, Request $request): Response {
        $mb = $this->ownedMailbox($mid);
        if (!$mb) return Response::redirect('/panel/email');
        $field = (string) $request->input('field', '');
        $operator = (string) $request->input('operator', '');
        $value = trim((string) $request->input('value', ''));
        $action = (string) $request->input('action', '');
        $target = trim((string) $request->input('target', ''));
        $valid = in_array($field, \Modules\Email\Services\SieveService::FIELDS, true)
            && in_array($operator, \Modules\Email\Services\SieveService::OPERATORS, true)
            && in_array($action, \Modules\Email\Services\SieveService::ACTIONS, true)
            && $value !== '' && strlen($value) <= 255;
        if (!$valid) {
            app('session')->flash('error', 'Invalid filter rule.');
            return Response::redirect('/panel/email/mailbox/' . $mid . '/filters');
        }
        if ($action === 'redirect' && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            app('session')->flash('error', 'Redirect needs a valid email address.');
            return Response::redirect('/panel/email/mailbox/' . $mid . '/filters');
        }
        $max = (int) (app('db')->scalar('SELECT MAX(`position`) FROM `mail_filters` WHERE `mailbox_id` = ?', [$mid]) ?? -1);
        app('db')->insert('mail_filters', [
            'mailbox_id' => $mid, 'position' => $max + 1, 'field' => $field,
            'operator' => $operator, 'value' => $value, 'action' => $action,
            'target' => $target !== '' ? $target : null, 'enabled' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            app(\Modules\Email\Services\SieveService::class)->sync($mid);
            app('session')->flash('success', 'Filter rule added.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Rule saved but delivery update failed: ' . $e->getMessage());
        }
        return Response::redirect('/panel/email/mailbox/' . $mid . '/filters');
    }

    public function mailboxFilterDelete(int $mid, int $fid): Response {
        $mb = $this->ownedMailbox($mid);
        if (!$mb) return Response::redirect('/panel/email');
        app('db')->delete('mail_filters', '`id` = ? AND `mailbox_id` = ?', [$fid, $mid]);
        try {
            app(\Modules\Email\Services\SieveService::class)->sync($mid);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Rule deleted but delivery update failed: ' . $e->getMessage());
            return Response::redirect('/panel/email/mailbox/' . $mid . '/filters');
        }
        app('session')->flash('success', 'Filter rule deleted.');
        return Response::redirect('/panel/email/mailbox/' . $mid . '/filters');
    }
}