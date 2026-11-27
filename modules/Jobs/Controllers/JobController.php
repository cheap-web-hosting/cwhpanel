<?php
declare(strict_types=1);
namespace Modules\Jobs\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;

class JobController {
    public function index(Request $request): Response {
        $status = trim((string)$request->query('status',''));
        $where = '1=1'; $params = [];
        if ($status !== '') { $where .= ' AND `status`=?'; $params[] = $status; }
        $jobs = app('db')->select("SELECT * FROM `jobs` WHERE {$where} ORDER BY `id` DESC LIMIT 200", $params);
        $content = view('admin/jobs/index', ['title'=>'Jobs','jobs'=>$jobs,'status'=>$status], 'layouts/admin');
        return new Response((string)$content);
    }
    public function retry(int $id): Response {
        if (app('db')->selectOne('SELECT `id` FROM `jobs` WHERE `id`=?',[$id]) === null) {
            app('session')->flash('error','Job not found.');
            return Response::redirect('/admin/jobs');
        }
        app('db')->update('jobs', ['status'=>'pending','attempts'=>0,'updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$id]);
        app('session')->flash('success','Job queued for retry.');
        return Response::redirect('/admin/jobs');
    }
    public function cancel(int $id): Response {
        if (app('db')->selectOne('SELECT `id` FROM `jobs` WHERE `id`=?',[$id]) === null) {
            app('session')->flash('error','Job not found.');
            return Response::redirect('/admin/jobs');
        }
        app('db')->update('jobs', ['status'=>'cancelled','updated_at'=>date('Y-m-d H:i:s')], '`id`=?',[$id]);
        app('session')->flash('success','Job cancelled.');
        return Response::redirect('/admin/jobs');
    }
}