<?php
declare(strict_types=1);
namespace Modules\Api\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;

class AdminApiController {
    public function index(): Response {
        $keys = app('db')->select('SELECT k.*, u.`email` FROM `api_keys` k LEFT JOIN `users` u ON u.`id`=k.`user_id` ORDER BY k.`id` DESC');
        $content = view('admin/api/keys', ['title'=>'API Keys','keys'=>$keys,'enabled'=>self::keysEnabled()], 'layouts/admin');
        return new Response((string)$content);
    }

    /**
     * API keys are minted but no HTTP endpoint consumes them yet. Minting
     * fake credentials is a misleading surface, so issuance is locked behind
     * an explicit API_KEYS_ENABLED=1 opt-in until a consumer exists.
     */
    public static function keysEnabled(): bool
    {
        return env('API_KEYS_ENABLED', '0') === '1';
    }
    public function webhooks(): Response {
        $webhooks = app('db')->select('SELECT w.*, a.`username` FROM `webhooks` w LEFT JOIN `accounts` a ON a.`id`=w.`account_id` ORDER BY w.`id` DESC');
        $content = view('admin/api/webhooks', ['title'=>'Webhooks','webhooks'=>$webhooks], 'layouts/admin');
        return new Response((string)$content);
    }
    public function storeKey(Request $request): Response {
        if (!self::keysEnabled()) {
            app('session')->flash('error','API keys are not enabled on this deployment (API_KEYS_ENABLED=1).');
            return Response::redirect('/admin/api');
        }
        $name = trim((string)$request->input('name',''));
        if ($name === '') { app('session')->flash('error','Name required.'); return Response::redirect('/admin/api'); }
        $userId = (int)$request->input('user_id',0);
        // A key's principal is its OWNER (auth.api_key derives the actor from
        // users.id), so minting one for somebody else is minting a credential
        // that outranks the caller. Without this check a limited admin holding
        // only `api.keys.manage` could create a superadmin-owned key and then
        // use /api/v1/accounts* (create/suspend/terminate + cross-account
        // reads) — the same escalation Gate::roleWithinActor blocks for
        // admin-user management.
        $actor = app('auth')->user();
        if ($userId === 0 && $actor !== null) {
            $userId = (int) $actor->id; // default: the caller's own key
        }
        if ($userId !== 0) {
            $target = app('db')->selectOne('SELECT `id`, `is_admin`, `role_id` FROM `users` WHERE `id` = ?', [$userId]);
            if ($target === null) {
                app('session')->flash('error','Selected user does not exist.');
                return Response::redirect('/admin/api');
            }
            if ($actor !== null) {
                $gate = app(\App\Foundation\Auth\Gate::class);
                $escalation = (int) $target['is_admin'] !== 1 || !$gate->isFullAdmin($actor);
                if ($escalation) {
                    app('session')->flash('error','You API keys are administrator-only.');
                    return Response::redirect('/admin/api');
                }
            }
        }
        $key = 'ck_' . bin2hex(random_bytes(24));
        // Store only a hash: a DB read must not yield a usable key. The full
        // value is shown once (flash) at creation.
        $keyHash = hash('sha256', $key);
        $id = app('db')->insert('api_keys', ['user_id'=>$userId,'name'=>$name,'key'=>$keyHash,'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        audit('api.key.created','api_key',$id);
        app('session')->flash('success','API key created: '.$key);
        return Response::redirect('/admin/api');
    }
    public function revokeKey(int $id): Response {
        app('db')->update('api_keys', ['status'=>'revoked','updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$id]);
        audit('api.key.revoked','api_key',$id);
        app('session')->flash('success','API key revoked.');
        return Response::redirect('/admin/api');
    }
    public function storeWebhook(Request $request): Response {
        try { $data = $request->validate(['event'=>'required|max:191','url'=>'required|max:255','account_id'=>'required|integer']); }
        catch(ValidationException $e) { app('session')->flash('error','Invalid webhook.'); return Response::redirect('/admin/api/webhooks'); }
        if (preg_match('/^[A-Za-z0-9._-]{1,191}$/', (string) $data['event']) !== 1) { app('session')->flash('error','Invalid event name.'); return Response::redirect('/admin/api/webhooks'); }
        if (app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `id` = ?', [(int) $data['account_id']]) === null) { app('session')->flash('error','Selected account does not exist.'); return Response::redirect('/admin/api/webhooks'); }
        $urlError = \Modules\Api\Services\WebhookPolicy::checkUrl((string) $data['url']);
        if ($urlError !== null) { app('session')->flash('error', $urlError); return Response::redirect('/admin/api/webhooks'); }
        app('db')->insert('webhooks', ['account_id'=>(int)$data['account_id'],'event'=>$data['event'],'url'=>$data['url'],'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        audit('api.webhook.created','webhook',null,['event'=>$data['event']]);
        app('session')->flash('success','Webhook created.');
        return Response::redirect('/admin/api/webhooks');
    }
    public function deleteWebhook(int $id): Response {
        app('db')->delete('webhooks','`id`=?',[$id]);
        app('session')->flash('success','Webhook deleted.');
        return Response::redirect('/admin/api/webhooks');
    }
}