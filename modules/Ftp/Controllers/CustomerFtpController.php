<?php
declare(strict_types=1);
namespace Modules\Ftp\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Ftp\Services\FtpService;

class CustomerFtpController {
    private function accountId(): ?int {
        $user = app('auth')->user(); if (!$user) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id`=?',[$user->id]);
        return $row ? (int)$row['id'] : null;
    }
    public function index(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $accounts = app('db')->select("SELECT * FROM `ftp_accounts` WHERE `account_id`=? ORDER BY `username`",[$accountId]);
        $content = view('customer/ftp/index', ['title'=>'FTP Accounts','accounts'=>$accounts], 'layouts/customer');
        return new Response((string)$content);
    }
    public function create(): Response {
        $content = view('customer/ftp/form', ['title'=>'Add FTP Account','old'=>[]], 'layouts/customer');
        return new Response((string)$content);
    }
    public function store(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        try { $data = $request->validate(['username'=>'required|alpha_dash|max:64','password'=>'required|min:8','home_dir'=>'required|max:255','quota_mb'=>'integer']); }
        catch(ValidationException $e) { return $this->backWithErrors($request,$e,'/panel/ftp/create'); }
        if (app('db')->selectOne("SELECT `id` FROM `ftp_accounts` WHERE `username`=?",[strtolower($data['username'])])) { app('session')->flash('error','Username exists.'); return Response::redirect('/panel/ftp/create'); }
        try {
            \Modules\Packages\Services\LimitService::require($accountId, 'ftp');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/ftp/create');
        }
        $account = app('db')->selectOne("SELECT * FROM `accounts` WHERE `id`=?",[$accountId]);
        if ($account === null) { app('session')->flash('error','Account not found.'); return Response::redirect('/panel/ftp/create'); }
        try {
            $ftpUser = FtpService::assertCreatableUsername((string) $data['username'], (string) $account['username']);
            $home = FtpService::assertHomeInTree($account['username'], (string) $data['home_dir']);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/ftp/create');
        }
        $id = app('db')->insert('ftp_accounts', ['account_id'=>$accountId,'username'=>$ftpUser,'password'=>hash('sha256',$data['password']),'home_dir'=>$home,'quota_mb'=>(int)($data['quota_mb']??1024),'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        try {
            app(FtpService::class)->setup();
            app(FtpService::class)->provisionLogin($account, $ftpUser, $home, (string) $data['password']);
        } catch (\Throwable $e) {
            app('db')->delete('ftp_accounts', '`id`=?', [$id]);
            app('session')->flash('error','FTP account could not be provisioned: '.$e->getMessage());
            return Response::redirect('/panel/ftp/create');
        }
        audit('ftp.account.created','ftp_account',$id,['username'=>$data['username']]);
        app('session')->flash('success','FTP account created.');
        return Response::redirect('/panel/ftp');
    }
    public function destroy(int $id): Response {
        $accountId = $this->accountId();
        $row = app('db')->selectOne('SELECT `username` FROM `ftp_accounts` WHERE `id`=? AND `account_id`=?',[$id,$accountId]);
        if ($row === null) { app('session')->flash('error','FTP account not found.'); return Response::redirect('/panel/ftp'); }
        // Remove the system login FIRST: deleting the row first would orphan
        // a live login with no managing row left if the agent call fails.
        try {
            app(FtpService::class)->removeLogin((string) $row['username']);
        } catch (\Throwable $e) {
            app('session')->flash('error','System login could not be removed, account kept: '.$e->getMessage());
            return Response::redirect('/panel/ftp');
        }
        app('db')->delete('ftp_accounts','`id`=? AND `account_id`=?',[$id,$accountId]);
        app('session')->flash('success','FTP account deleted.');
        return Response::redirect('/panel/ftp');
    }
    public function changePassword(int $id, Request $request): Response {
        $accountId = $this->accountId();
        try { $data = $request->validate(['password'=>'required|min:8']); }
        catch(ValidationException $e) { app('session')->flash('error','Invalid password.'); return Response::redirect('/panel/ftp'); }
        $row = app('db')->selectOne('SELECT `username`, `account_id` FROM `ftp_accounts` WHERE `id`=? AND `account_id`=?',[$id,$accountId]);
        if ($row === null) { app('session')->flash('error','FTP account not found.'); return Response::redirect('/panel/ftp'); }
        app('db')->update('ftp_accounts', ['password'=>hash('sha256',$data['password']),'updated_at'=>date('Y-m-d H:i:s')], '`id`=? AND `account_id`=?',[$id,$accountId]);
        $ftpOwner = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `id`=?', [(int) $row['account_id']]);
        try {
            app(FtpService::class)->setPassword((string) $row['username'], (string) $data['password'], (string) ($ftpOwner['username'] ?? $row['username']));
        } catch (\Throwable $e) {
            app('session')->flash('error','Password saved but login update failed: '.$e->getMessage());
            return Response::redirect('/panel/ftp');
        }
        app('session')->flash('success','Password changed.');
        return Response::redirect('/panel/ftp');
    }
    private function backWithErrors(Request $r, ValidationException $e, string $path): Response {
        app('session')->flashInput($r->all());
        foreach ($e->errors() as $f=>$ms) foreach ($ms as $m) app('session')->pushError($f,$m);
        return Response::redirect($path);
    }
}