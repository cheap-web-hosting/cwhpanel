<?php
declare(strict_types=1);
namespace Modules\Email\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Email\Services\MailService;
use Modules\Email\Services\MailingListService;

class AdminEmailController {
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
    public function index(): Response {
        $domains = app('db')->select('SELECT d.*, a.`username` FROM `mail_domains` d LEFT JOIN `accounts` a ON a.`id`=d.`account_id` ORDER BY d.`domain`');
        $content = view('admin/email/index', ['title'=>'Mail Domains','domains'=>$domains], 'layouts/admin');
        return new Response((string) $content);
    }
    public function createDomain(): Response {
        $accounts = app('db')->select("SELECT * FROM `accounts` WHERE `status`=? ORDER BY `username`", ['active']);
        $content = view('admin/email/domain-create', ['title'=>'Add Mail Domain','accounts'=>$accounts], 'layouts/admin');
        return new Response((string) $content);
    }
    public function storeDomain(Request $request): Response {
        try { $data = $request->validate(['domain'=>'required|max:255','account_id'=>'required|integer']); }
        catch(ValidationException $e) { app('session')->flashInput($request->all()); app('session')->flash('error','Validation failed.'); return Response::redirect('/admin/email/domains/create'); }
        $domain = strtolower($data['domain']);
        if (app('db')->selectOne("SELECT `id` FROM `mail_domains` WHERE `domain`=?",[$domain])) { app('session')->flash('error','Domain already exists.'); return Response::redirect('/admin/email/domains/create'); }
        $id = app('db')->insert('mail_domains', ['domain'=>$domain,'account_id'=>(int)$data['account_id'],'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        audit('email.domain.created','mail_domain',$id,['domain'=>$domain]);
        app('session')->flash('success','Mail domain added.');
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function showDomain(int $id): Response {
        $domain = app('db')->selectOne("SELECT d.*, a.`username` FROM `mail_domains` d LEFT JOIN `accounts` a ON a.`id`=d.`account_id` WHERE d.`id`=?",[$id]);
        $mailboxes = app('db')->select("SELECT * FROM `mailboxes` WHERE `domain_id`=? ORDER BY `email`",[$id]);
        $forwarders = app('db')->select("SELECT * FROM `forwarders` WHERE `domain_id`=? ORDER BY `source`",[$id]);
        $content = view('admin/email/show', ['title'=>'Mail Domain: '.$domain['domain'],'domain'=>$domain,'mailboxes'=>$mailboxes,'forwarders'=>$forwarders,'lists'=>app(MailingListService::class)->forDomain($id),'listMembers'=>self::listMembers($id),'quotaMax'=>\Modules\Email\Services\MailService::mailboxQuotaMax((int) ($domain['account_id'] ?? 0))], 'layouts/admin');
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
    public function destroyDomain(int $id): Response {
        $domain = app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=?",[$id]);
        if (!$domain) return Response::redirect('/admin/email');
        app('db')->delete('mailboxes','`domain_id`=?',[$id]);
        app('db')->delete('forwarders','`domain_id`=?',[$id]);
        foreach (app('db')->select('SELECT `id` FROM `mailing_lists` WHERE `domain_id`=?',[$id]) as $l) {
            app(MailingListService::class)->delete((int)$l['id']);
        }
        app('db')->delete('mail_domains','`id`=?',[$id]);
        $this->syncMail();
        audit('email.domain.deleted','mail_domain',$id);
        app('session')->flash('success','Mail domain deleted.');
        return Response::redirect('/admin/email');
    }
    public function addMailbox(int $id, Request $request): Response {
        $domain = app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=?",[$id]);
        if (!$domain) return Response::redirect('/admin/email');
        try { $data = $request->validate(['email'=>'required|max:255','password'=>'required|min:8','quota_mb'=>'integer']); }
        catch(ValidationException $e) { foreach($e->errors() as $f=>$ms) foreach($ms as $m) app('session')->pushError($f,$m); return Response::redirect('/admin/email/domains/'.$id); }
        [$email, $error] = MailService::normalizeMailbox($domain, (string) $data['email']);
        if ($error !== null) { app('session')->flash('error', $error); return Response::redirect('/admin/email/domains/'.$id); }
        try {
            \Modules\Packages\Services\LimitService::require((int) $domain['account_id'], 'mailboxes');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/email/domains/'.$id);
        }
        $quota = max(1, (int) ($data['quota_mb'] ?? 100));
        $quotaMax = \Modules\Email\Services\MailService::mailboxQuotaMax((int) $domain['account_id']);
        if ($quotaMax > 0 && $quota > $quotaMax) { app('session')->flash('error', "Mailbox size may not exceed {$quotaMax} MB on this hosting package."); return Response::redirect('/admin/email/domains/'.$id); }
        try {
            $mbId = app('db')->insert('mailboxes', ['account_id'=>$domain['account_id'],'domain_id'=>$id,'email'=>$email,'password'=>MailService::hash($data['password']),'quota_mb'=>$quota,'max_out_per_hour'=>MailService::mailboxHourlyLimit((int)$domain['account_id']),'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) { app('session')->flash('error','A mailbox with that address already exists.'); }
            else { app('session')->flash('error','Could not create mailbox.'); }
            return Response::redirect('/admin/email/domains/'.$id);
        }
        $this->syncMail();
        audit('email.mailbox.created','mailbox',$mbId);
        app('session')->flash('success','Mailbox added.');
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function deleteMailbox(int $id, int $mid): Response {
        app('db')->delete('mailboxes','`id`=? AND `domain_id`=?',[$mid,$id]);
        $this->syncMail();
        app('session')->flash('success','Mailbox deleted.');
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function changePassword(int $id, int $mid, Request $request): Response {
        try { $data = $request->validate(['password'=>'required|min:8']); }
        catch(ValidationException $e) { app('session')->flash('error','Invalid password.'); return Response::redirect('/admin/email/domains/'.$id); }
        app('db')->update('mailboxes', ['password'=>MailService::hash($data['password']),'updated_at'=>date('Y-m-d H:i:s')], '`id`=? AND `domain_id`=?',[$mid,$id]);
        $this->syncMail();
        audit('email.mailbox.password','mailbox',$mid);
        app('session')->flash('success','Password changed.');
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function addForwarder(int $id, Request $request): Response {
        try { $data = $request->validate(['source'=>'required|max:255','destination'=>'required|max:255']); }
        catch(ValidationException $e) { foreach($e->errors() as $f=>$ms) foreach($ms as $m) app('session')->pushError($f,$m); return Response::redirect('/admin/email/domains/'.$id); }
        $domain = app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=?",[$id]);
        if (!$domain) return Response::redirect('/admin/email');
        [$source, $dest, $error] = MailService::normalizeForwarder($domain, (string) $data['source'], (string) $data['destination']);
        if ($error !== null) { app('session')->flash('error', $error); return Response::redirect('/admin/email/domains/'.$id); }
        \Modules\Packages\Services\LimitService::requireHardCap((int) $domain['account_id'], 'forwarders');
        app('db')->insert('forwarders', ['account_id'=>$domain['account_id'],'domain_id'=>$id,'source'=>$source,'destination'=>$dest,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        $this->syncMail();
        app('session')->flash('success','Forwarder added: '.$source);
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function deleteForwarder(int $id, int $fid): Response {
        app('db')->delete('forwarders','`id`=? AND `domain_id`=?',[$fid,$id]);
        $this->syncMail();
        app('session')->flash('success','Forwarder deleted.');
        return Response::redirect('/admin/email/domains/'.$id);
    }
    private function findList(int $id, int $lid): ?array {
        return app('db')->selectOne('SELECT * FROM `mailing_lists` WHERE `id`=? AND `domain_id`=?',[$lid,$id]);
    }
    public function addList(int $id, Request $request): Response {
        $domain = app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=?",[$id]);
        if (!$domain) return Response::redirect('/admin/email');
        try {
            $lid = app(MailingListService::class)->create((int)$domain['account_id'], $domain,
                (string)$request->input('name',''), (string)$request->input('description',''), (string)$request->input('posting','members'));
        } catch (\RuntimeException $e) { app('session')->flash('error',$e->getMessage()); return Response::redirect('/admin/email/domains/'.$id); }
        try {
            $this->syncMail();
            audit('email.list.created','mailing_list',$lid);
            app('session')->flash('success','Mailing list added.');
        } catch (\Throwable $e) { app('session')->flash('error','List saved but mail sync failed: '.$e->getMessage()); }
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function deleteList(int $id, int $lid): Response {
        if (!$this->findList($id,$lid)) return Response::redirect('/admin/email/domains/'.$id);
        app(MailingListService::class)->delete($lid);
        $this->syncMail();
        audit('email.list.deleted','mailing_list',$lid);
        app('session')->flash('success','Mailing list deleted.');
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function setListPosting(int $id, int $lid, Request $request): Response {
        if (!$this->findList($id,$lid)) return Response::redirect('/admin/email/domains/'.$id);
        try {
            app(MailingListService::class)->setPosting($lid, (string)$request->input('posting','members'));
            $this->syncMail();
            app('session')->flash('success','Posting policy updated.');
        } catch (\RuntimeException $e) { app('session')->flash('error',$e->getMessage()); }
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function addListMember(int $id, int $lid, Request $request): Response {
        $domain = app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=?",[$id]);
        $list = $domain ? $this->findList($id,$lid) : null;
        if (!$domain || !$list) return Response::redirect('/admin/email/domains/'.$id);
        try {
            app(MailingListService::class)->addMember($list, $domain, (string)$request->input('email',''), true);
            $this->syncMail();
            app('session')->flash('success','Member subscribed.');
        } catch (\RuntimeException $e) { app('session')->flash('error',$e->getMessage()); }
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function removeListMember(int $id, int $lid, int $mid): Response {
        if (!$this->findList($id,$lid)) return Response::redirect('/admin/email/domains/'.$id);
        app(MailingListService::class)->removeMember($lid,$mid);
        $this->syncMail();
        app('session')->flash('success','Member removed.');
        return Response::redirect('/admin/email/domains/'.$id);
    }
    public function setCatchall(int $id, Request $request): Response {
        $domain = app('db')->selectOne("SELECT * FROM `mail_domains` WHERE `id`=?",[$id]);
        if (!$domain) return Response::redirect('/admin/email');
        $target = strtolower(trim((string) $request->input('catchall', '')));
        if ($target !== '' && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            app('session')->flash('error','Catch-all must be a valid email address (or empty to bounce unknown recipients).');
            return Response::redirect('/admin/email/domains/'.$id);
        }
        app('db')->update('mail_domains', ['catchall' => $target !== '' ? $target : null], '`id`=?', [$id]);
        try {
            $this->syncMail();
            audit('email.catchall.updated','mail_domain',$id,['catchall'=>$target]);
            app('session')->flash('success', $target !== '' ? 'Catch-all address updated.' : 'Catch-all cleared; unknown recipients will bounce.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Catch-all saved but mail sync failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/email/domains/'.$id);
    }

    public function queue(): Response {
        $raw = '';
        $messages = [];
        $error = '';
        try {
            $result = app('provisioning')->execute('mail.queue.list');
            $raw = $result['output'] ?? '';
            // postqueue -j emits one JSON object per line — decode each line.
            foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $obj = json_decode($line, true);
                if (is_array($obj) && isset($obj['queue_id'])) {
                    $messages[$obj['queue_id']] = $obj;
                }
            }
            if ($raw !== '' && $messages === []) {
                $error = 'Mail queue agent returned an unreadable response (postqueue -j parse failed).';
            }
        } catch (\Throwable $e) {
            $raw = 'Error: ' . $e->getMessage();
            $error = $e->getMessage();
        }

        $content = view('admin/email/queue', [
            'title' => 'Mail Queue',
            'messages' => $messages,
            'raw' => $raw,
            'error' => $error,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function queueFlush(): Response {
        try { app('provisioning')->execute('mail.queue.flush'); app('session')->flash('success', 'Queue flushed.'); }
        catch (\Throwable $e) { app('session')->flash('error', $e->getMessage()); }
        return Response::redirect('/admin/email/queue');
    }

    public function queueDelete(string $id): Response {
        try { app('provisioning')->execute('mail.queue.delete', ['id' => $id]); app('session')->flash('success', 'Message removed.'); }
        catch (\Throwable $e) { app('session')->flash('error', $e->getMessage()); }
        return Response::redirect('/admin/email/queue');
    }

    public function queueRequeue(string $id): Response {
        try { app('provisioning')->execute('mail.queue.requeue', ['id' => $id]); app('session')->flash('success', 'Message requeued.'); }
        catch (\Throwable $e) { app('session')->flash('error', $e->getMessage()); }
        return Response::redirect('/admin/email/queue');
    }

    private function findMailbox(int $mid): ?array {
        return app('db')->selectOne('SELECT m.*, a.`username` FROM `mailboxes` m JOIN `accounts` a ON a.`id`=m.`account_id` WHERE m.`id`=?', [$mid]);
    }

    public function mailboxFilters(int $mid): Response {
        $mb = $this->findMailbox($mid);
        if ($mb === null) return Response::redirect('/admin/email');
        $state = app(\Modules\Email\Services\SieveService::class)->forMailbox($mid);
        $content = view('admin/email/filters-mailbox', [
            'title' => 'Filters & Autoresponder: ' . $mb['email'],
            'mailbox' => $mb,
            'auto' => $state['auto'],
            'filters' => $state['filters'],
        ], 'layouts/admin');
        return new Response((string) $content);
    }

    public function mailboxFiltersStore(int $mid, Request $request): Response {
        $mb = $this->findMailbox($mid);
        if ($mb === null) return Response::redirect('/admin/email');
        $enabled = $request->input('enabled') ? 1 : 0;
        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));
        if (strlen($subject) > 255 || strlen($body) > 8000) {
            app('session')->flash('error', 'Subject or message too long.');
            return Response::redirect('/admin/email/mailbox/' . $mid . '/filters');
        }
        if ($enabled && ($subject === '' || $body === '')) {
            app('session')->flash('error', 'Subject and message are required to enable the autoresponder.');
            return Response::redirect('/admin/email/mailbox/' . $mid . '/filters');
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
            audit('mail.autoresponder.updated', 'mailbox', $mid);
            app('session')->flash('success', 'Autoresponder updated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Autoresponder saved but delivery update failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/email/mailbox/' . $mid . '/filters');
    }

    public function mailboxFilterAdd(int $mid, Request $request): Response {
        $mb = $this->findMailbox($mid);
        if ($mb === null) return Response::redirect('/admin/email');
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
            return Response::redirect('/admin/email/mailbox/' . $mid . '/filters');
        }
        if ($action === 'redirect' && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            app('session')->flash('error', 'Redirect needs a valid email address.');
            return Response::redirect('/admin/email/mailbox/' . $mid . '/filters');
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
            audit('mail.filter.added', 'mailbox', $mid);
            app('session')->flash('success', 'Filter rule added.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Rule saved but delivery update failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/email/mailbox/' . $mid . '/filters');
    }

    public function mailboxFilterDelete(int $mid, int $fid): Response {
        if ($this->findMailbox($mid) === null) return Response::redirect('/admin/email');
        app('db')->delete('mail_filters', '`id` = ? AND `mailbox_id` = ?', [$fid, $mid]);
        try {
            app(\Modules\Email\Services\SieveService::class)->sync($mid);
            audit('mail.filter.deleted', 'mailbox', $mid);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Rule deleted but delivery update failed: ' . $e->getMessage());
            return Response::redirect('/admin/email/mailbox/' . $mid . '/filters');
        }
        app('session')->flash('success', 'Filter rule deleted.');
        return Response::redirect('/admin/email/mailbox/' . $mid . '/filters');
    }
}