<?php
declare(strict_types=1);
namespace Modules\Cron\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Cron\Services\CronService;

class AdminCronController {
    public function index(): Response {
        $jobs = app('db')->select('SELECT c.*, a.`username` FROM `cron_jobs` c LEFT JOIN `accounts` a ON a.`id`=c.`account_id` ORDER BY c.`id` DESC');
        // Refresh per account (one bulk op each); failures keep last-known values.
        foreach (array_unique(array_map(fn($j) => (int) $j['account_id'], $jobs)) as $accountId) {
            try { app(CronService::class)->refreshFromState($accountId); } catch (\Throwable $e) { app('logger')->warning('Admin cron refresh failed for account ' . $accountId . ': ' . $e->getMessage()); }
        }
        $jobs = app('db')->select('SELECT c.*, a.`username` FROM `cron_jobs` c LEFT JOIN `accounts` a ON a.`id`=c.`account_id` ORDER BY c.`id` DESC');
        $content = view('admin/cron/index', ['title'=>'Cron Jobs','jobs'=>$jobs], 'layouts/admin');
        return new Response((string)$content);
    }
    public function create(): Response {
        $accounts = app('db')->select("SELECT * FROM `accounts` WHERE `status`=? ORDER BY `username`", ['active']);
        $content = view('admin/cron/form', ['title'=>'Add Cron Job','accounts'=>$accounts,'old'=>[]], 'layouts/admin');
        return new Response((string)$content);
    }
    public function store(Request $request): Response {
        try { $data = $request->validate(['schedule'=>'required|max:191','command'=>'required|max:255','account_id'=>'required|integer']); }
        catch(ValidationException $e) { return $this->backWithErrors($request,$e,'/admin/cron/create'); }
        try {
            $schedule = CronService::assertSingleLine($data['schedule'], 'schedule');
            $command = CronService::assertSingleLine($data['command'], 'command');
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/cron/create');
        }
        try {
            \Modules\Packages\Services\LimitService::require((int) $data['account_id'], 'cron');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/cron/create');
        }
        $id = app('db')->insert('cron_jobs', ['account_id'=>(int)$data['account_id'],'schedule'=>$schedule,'command'=>$command,'status'=>'enabled','jailed'=>$request->input('jailed','0')==='1'?1:0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        app(CronService::class)->sync((int)$data['account_id']);
        audit('cron.created','cron_job',$id);
        app('session')->flash('success','Cron job created.');
        return Response::redirect('/admin/cron');
    }
    public function destroy(int $id): Response {
        $job = app('db')->selectOne("SELECT * FROM `cron_jobs` WHERE `id`=?",[$id]);
        if ($job) { app(CronService::class)->deleteJob((int) $job['account_id'], $id); }
        app('session')->flash('success','Cron job deleted.');
        return Response::redirect('/admin/cron');
    }
    public function toggle(int $id): Response {
        $job = app('db')->selectOne("SELECT * FROM `cron_jobs` WHERE `id`=?",[$id]);
        if (!$job) return Response::redirect('/admin/cron');
        $new = $job['status']==='enabled' ? 'disabled' : 'enabled';
        app('db')->update('cron_jobs', ['status'=>$new,'updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$id]);
        app(CronService::class)->sync((int)$job['account_id']);
        app('session')->flash('success','Cron job '.$new.'.');
        return Response::redirect('/admin/cron');
    }
    public function runNow(int $id): Response {
        $job = app('db')->selectOne("SELECT * FROM `cron_jobs` WHERE `id`=?",[$id]);
        if (!$job) return Response::redirect('/admin/cron');
        try {
            $res = app(CronService::class)->runNow((int) $job['account_id'], $id);
            $ms = number_format($res['duration_ms'] / 1000, 2) . 's';
            if ($res['exit'] === 0) {
                app('session')->flash('success', "Ran in {$ms} (exit 0).");
            } else {
                app('session')->flash('error', "Ran in {$ms} but exited {$res['exit']}.");
            }
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Run failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/cron');
    }
    private function backWithErrors(Request $r, ValidationException $e, string $path): Response {
        app('session')->flashInput($r->all());
        foreach ($e->errors() as $f=>$ms) foreach ($ms as $m) app('session')->pushError($f,$m);
        return Response::redirect($path);
    }
}