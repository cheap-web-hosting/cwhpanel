<?php
declare(strict_types=1);
namespace Modules\Ftp\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Ftp\Services\FtpService;

class AdminFtpController {
    public function index(): Response {
        $accounts = app('db')->select('SELECT f.*, a.`username` AS account_username FROM `ftp_accounts` f LEFT JOIN `accounts` a ON a.`id`=f.`account_id` ORDER BY f.`username`');
        $content = view('admin/ftp/index', ['title'=>'FTP Accounts','accounts'=>$accounts], 'layouts/admin');
        return new Response((string)$content);
    }
    public function create(): Response {
        $hostAccounts = app('db')->select("SELECT * FROM `accounts` WHERE `status`=? ORDER BY `username`", ['active']);
        $content = view('admin/ftp/form', ['title'=>'Add FTP Account','hostAccounts'=>$hostAccounts,'old'=>[]], 'layouts/admin');
        return new Response((string)$content);
    }
    public function store(Request $request): Response {
        try { $data = $request->validate(['username'=>'required|alpha_dash|max:64','password'=>'required|min:8','home_dir'=>'required|max:255','account_id'=>'required|integer','quota_mb'=>'integer']); }
        catch(ValidationException $e) { return $this->backWithErrors($request,$e,'/admin/ftp/create'); }
        if (app('db')->selectOne("SELECT `id` FROM `ftp_accounts` WHERE `username`=?",[strtolower($data['username'])])) { app('session')->flash('error','Username exists.'); return Response::redirect('/admin/ftp/create'); }
        try {
            \Modules\Packages\Services\LimitService::require((int) $data['account_id'], 'ftp');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/ftp/create');
        }
        $account = app('db')->selectOne("SELECT * FROM `accounts` WHERE `id`=?",[(int)$data['account_id']]);
        if ($account === null) {
            app('session')->flash('error','Invalid account.');
            return Response::redirect('/admin/ftp/create');
        }
        try {
            $ftpUser = FtpService::assertCreatableUsername((string) $data['username'], (string) $account['username']);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/ftp/create');
        }
        $id = app('db')->insert('ftp_accounts', ['account_id'=>(int)$data['account_id'],'username'=>$ftpUser,'password'=>hash('sha256',$data['password']),'home_dir'=>$data['home_dir'],'quota_mb'=>(int)($data['quota_mb']??1024),'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);

        // Back the row with a dedicated system login sharing the account
        // UID/GID (own password, home and lock state) + vsftpd mapping.
        // Roll the row back when provisioning fails: a login-less row could
        // never authenticate and would only confuse later creates.
        try {
            $home = FtpService::assertHomeInTree($account['username'], (string) $data['home_dir']);
            app('db')->update('ftp_accounts', ['home_dir' => $home, 'updated_at' => date('Y-m-d H:i:s')], '`id`=?', [$id]);
            app(FtpService::class)->setup();
            app(FtpService::class)->provisionLogin($account, $ftpUser, $home, (string) $data['password']);
        } catch (\Throwable $e) {
            app('db')->delete('ftp_accounts', '`id`=?', [$id]);
            app('session')->flash('error','FTP account could not be provisioned: '.$e->getMessage());
            return Response::redirect('/admin/ftp/create');
        }

        audit('ftp.account.created','ftp_account',$id);
        app('session')->flash('success','FTP account created.');
        return Response::redirect('/admin/ftp');
    }
    public function destroy(int $id): Response {
        $row = app('db')->selectOne('SELECT `username` FROM `ftp_accounts` WHERE `id`=?',[$id]);
        if ($row === null) { app('session')->flash('error','FTP account not found.'); return Response::redirect('/admin/ftp'); }
        // Remove the system login FIRST: deleting the row first would orphan
        // a live login with no managing row left if the agent call fails.
        try {
            app(FtpService::class)->removeLogin((string) $row['username']);
        } catch (\Throwable $e) {
            app('session')->flash('error','System login could not be removed, account kept: '.$e->getMessage());
            return Response::redirect('/admin/ftp');
        }
        app('db')->delete('ftp_accounts','`id`=?',[$id]);
        audit('ftp.account.deleted','ftp_account',$id);
        app('session')->flash('success','FTP account deleted.');
        return Response::redirect('/admin/ftp');
    }
    public function suspend(int $id): Response {
        $row = app('db')->selectOne('SELECT `username` FROM `ftp_accounts` WHERE `id`=?',[$id]);
        if ($row === null) { app('session')->flash('error','FTP account not found.'); return Response::redirect('/admin/ftp'); }
        try {
            app(FtpService::class)->lock((string) $row['username']);
        } catch (\Throwable $e) {
            app('session')->flash('error','FTP login could not be locked: '.$e->getMessage());
            return Response::redirect('/admin/ftp');
        }
        app('db')->update('ftp_accounts', ['status'=>'suspended','updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$id]);
        audit('ftp.account.suspended','ftp_account',$id);
        app('session')->flash('success','FTP account suspended.');
        return Response::redirect('/admin/ftp');
    }
    public function unsuspend(int $id): Response {
        $row = app('db')->selectOne('SELECT `username` FROM `ftp_accounts` WHERE `id`=?',[$id]);
        if ($row === null) { app('session')->flash('error','FTP account not found.'); return Response::redirect('/admin/ftp'); }
        try {
            app(FtpService::class)->unlock((string) $row['username']);
        } catch (\Throwable $e) {
            app('session')->flash('error','FTP login could not be unlocked: '.$e->getMessage());
            return Response::redirect('/admin/ftp');
        }
        app('db')->update('ftp_accounts', ['status'=>'active','updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$id]);
        audit('ftp.account.unsuspended','ftp_account',$id);
        app('session')->flash('success','FTP account unsuspended.');
        return Response::redirect('/admin/ftp');
    }
    public function changePassword(int $id, Request $request): Response {
        try { $data = $request->validate(['password'=>'required|min:8']); }
        catch(ValidationException $e) { app('session')->flash('error','Invalid password.'); return Response::redirect('/admin/ftp'); }
        $ftp = app('db')->selectOne('SELECT f.`username`, f.`account_id` FROM `ftp_accounts` f WHERE f.`id`=?',[$id]);
        if ($ftp === null) { app('session')->flash('error','FTP account not found.'); return Response::redirect('/admin/ftp'); }
        app('db')->update('ftp_accounts', ['password'=>hash('sha256',$data['password']),'updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$id]);
        $ftpOwner = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `id`=?', [(int) $ftp['account_id']]);
        try {
            app(FtpService::class)->setPassword((string) $ftp['username'], (string) $data['password'], (string) ($ftpOwner['username'] ?? $ftp['username']));
        } catch (\Throwable $e) {
            app('session')->flash('error','Password saved but login update failed: '.$e->getMessage());
            return Response::redirect('/admin/ftp');
        }
        audit('ftp.account.password','ftp_account',$id);
        app('session')->flash('success','Password changed.');
        return Response::redirect('/admin/ftp');
    }
    private function backWithErrors(Request $r, ValidationException $e, string $path): Response {
        app('session')->flashInput($r->all());
        foreach ($e->errors() as $f=>$ms) foreach ($ms as $m) app('session')->pushError($f,$m);
        return Response::redirect($path);
    }
}