<?php
declare(strict_types=1);
namespace Modules\Cron\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Cron\Services\CronService;

class CustomerCronController {
    private function accountId(): ?int {
        $user = app('auth')->user(); if (!$user) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id`=?',[$user->id]);
        return $row ? (int)$row['id'] : null;
    }
    public function index(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        try { app(CronService::class)->refreshFromState($accountId); } catch (\Throwable $e) { app('logger')->warning('Customer cron refresh failed: ' . $e->getMessage()); }
        $jobs = app('db')->select("SELECT * FROM `cron_jobs` WHERE `account_id`=? ORDER BY `id` DESC",[$accountId]);
        $content = view('customer/cron/index', ['title'=>'Cron Jobs','jobs'=>$jobs], 'layouts/customer');
        return new Response((string)$content);
    }
    public function create(): Response {
        $content = view('customer/cron/form', ['title'=>'Add Cron Job','old'=>[]], 'layouts/customer');
        return new Response((string)$content);
    }
    public function store(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        if ($account !== null) {
            app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'cron');
        }
        try { $data = $request->validate(['schedule'=>'required|max:191','command'=>'required|max:255']); }
        catch(ValidationException $e) { return $this->backWithErrors($request,$e,'/panel/cron/create'); }
        try {
            $schedule = CronService::assertSingleLine($data['schedule'], 'schedule');
            $command = CronService::assertSingleLine($data['command'], 'command');
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/cron/create');
        }
        try {
            \Modules\Packages\Services\LimitService::require($accountId, 'cron');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/cron/create');
        }
        $id = app('db')->insert('cron_jobs', ['account_id'=>$accountId,'schedule'=>$schedule,'command'=>$command,'status'=>'enabled','jailed'=>$request->input('jailed','0')==='1'?1:0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        app(CronService::class)->sync($accountId);
        audit('cron.created','cron_job',$id);
        app('session')->flash('success','Cron job created.');
        return Response::redirect('/panel/cron');
    }
    public function destroy(int $id): Response {
        $accountId = $this->accountId();
        app(CronService::class)->deleteJob((int) $accountId, $id);
        app('session')->flash('success','Cron job deleted.');
        return Response::redirect('/panel/cron');
    }
    public function toggle(int $id): Response {
        $accountId = $this->accountId();
        $job = app('db')->selectOne("SELECT * FROM `cron_jobs` WHERE `id`=? AND `account_id`=?",[$id,$accountId]);
        if (!$job) return Response::redirect('/panel/cron');
        $new = $job['status']==='enabled' ? 'disabled' : 'enabled';
        app('db')->update('cron_jobs', ['status'=>$new,'updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$id]);
        app(CronService::class)->sync($accountId);
        app('session')->flash('success','Cron job '.$new.'.');
        return Response::redirect('/panel/cron');
    }
    public function runNow(int $id): Response {
        $accountId = $this->accountId();
        try {
            $res = app(CronService::class)->runNow((int) $accountId, $id);
            $ms = number_format($res['duration_ms'] / 1000, 2) . 's';
            if ($res['exit'] === 0) {
                app('session')->flash('success', "Ran in {$ms} (exit 0).");
            } else {
                app('session')->flash('error', "Ran in {$ms} but exited {$res['exit']}.");
            }
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Run failed: ' . $e->getMessage());
        }
        return Response::redirect('/panel/cron');
    }
    private function backWithErrors(Request $r, ValidationException $e, string $path): Response {
        app('session')->flashInput($r->all());
        foreach ($e->errors() as $f=>$ms) foreach ($ms as $m) app('session')->pushError($f,$m);
        return Response::redirect($path);
    }
}