<?php
declare(strict_types=1);
namespace Modules\Backups\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Backups\Services\BackupService;

class AdminBackupController {
    public function index(): Response {
        $backups = app('db')->select('SELECT b.*, a.`username` FROM `backups` b LEFT JOIN `accounts` a ON a.`id`=b.`account_id` ORDER BY b.`created_at` DESC');
        $svc = app(BackupService::class);
        foreach ($backups as &$b) { $b['artifacts'] = $svc->artifacts($b); }
        unset($b);
        $content = view('admin/backups/index', [
            'title' => 'Backups',
            'backups' => $backups,
            'plan' => $svc->plan(),
            'planComponents' => BackupService::COMPONENTS,
            'planEnabled' => BackupService::planComponents($svc->plan()),
            'planFrequencies' => BackupService::FREQUENCIES,
        ], 'layouts/admin');
        return new Response((string)$content);
    }

    public function plan(): Response
    {
        return Response::redirect('/admin/backups#backup-plan');
    }

    public function planSave(Request $request): Response
    {
        try {
            app(BackupService::class)->savePlan([
                'enabled' => (int) $request->input('enabled', 0),
                'frequency' => (string) $request->input('frequency', 'daily'),
                'components' => (array) $request->input('components', []),
                'keep_local' => (int) $request->input('keep_local', 7),
            ]);
            app('session')->flash('success', 'Full-account backup plan saved.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Plan not saved: ' . $e->getMessage());
        }
        return Response::redirect('/admin/backups#backup-plan');
    }

    public function planRun(): Response
    {
        $plan = app(BackupService::class)->plan();
        if ($plan === null || empty($plan['enabled'])) {
            app('session')->flash('error', 'Enable the plan before running it.');
            return Response::redirect('/admin/backups#backup-plan');
        }
        app('db')->insert('jobs', [
            'type' => 'backup.plan.run', 'status' => 'pending',
            'payload' => json_encode(['plan_id' => (int) $plan['id']]),
            'result' => null, 'attempts' => 0, 'max_attempts' => 1,
            'available_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        audit('backup.plan.queued', 'backup_plan', (int) $plan['id']);
        app('session')->flash('success', 'Plan queued — it runs in the background.');
        return Response::redirect('/admin/backups#backup-plan');
    }
    public function create(): Response {
        $accounts = app('db')->select("SELECT * FROM `accounts` WHERE `status`=? ORDER BY `username`", ['active']);
        $content = view('admin/backups/form', ['title'=>'Create Backup','accounts'=>$accounts], 'layouts/admin');
        return new Response((string)$content);
    }
    public function store(Request $request): Response {
        $accountId = (int)$request->input('account_id', 0);
        $type = $request->input('type', 'full');
        if (!in_array($type, ['files', 'databases', 'full'], true)) { app('session')->flash('error','Invalid backup type.'); return Response::redirect('/admin/backups/create'); }
        $account = app('db')->selectOne("SELECT * FROM `accounts` WHERE `id`=?",[$accountId]);
        if (!$account) { app('session')->flash('error','Invalid account.'); return Response::redirect('/admin/backups/create'); }
        $svc = app(BackupService::class);
        $name = $account['username'] . '-' . date('Ymd-His');
        $fileStatus = 'success';
        $dbStatus = 'success';
        if ($type === 'files' || $type === 'full') { $fileStatus = $svc->createStatus($accountId, $name); }
        if ($type === 'databases' || $type === 'full') {
            foreach ($svc->accountDatabases($accountId) as $dbName) {
                $s = $svc->dbCreate($dbName, $name . '.db.' . $dbName);
                if ($s !== 'success') { $dbStatus = $s; }
            }
        }
        $ok = $fileStatus === 'success' && $dbStatus === 'success';
        $dryRun = $fileStatus === 'dry-run' || $dbStatus === 'dry-run';
        $id = app('db')->insert('backups', ['account_id'=>$accountId,'name'=>$name.'.tar','type'=>$type,'size_bytes'=>0,'status'=>$ok ? 'completed' : ($dryRun ? 'dry-run' : 'failed'),'created_at'=>date('Y-m-d H:i:s')]);
        event('BackupCreated', ['backup_id'=>$id,'account_id'=>$accountId]);
        audit('backup.created','backup',$id,['name'=>$name.'.tar']);
        if ($ok) {
            app('session')->flash('success', 'Backup created.');
        } elseif ($dryRun) {
            app('session')->flash('error', 'Backup was recorded as a dry-run. Enable provisioning and restart the agent to create real backups (see PROVISIONING_ENABLED).');
        } else {
            app('session')->flash('error', 'Backup failed. Check the agent service status and provisioning logs under Server.');
        }
        return Response::redirect('/admin/backups');
    }
    public function download(int $id, Request $request): Response {
        $backup = app('db')->selectOne("SELECT * FROM `backups` WHERE `id`=?",[$id]);
        if (!$backup) return Response::redirect('/admin/backups');
        $path = app(BackupService::class)->downloadPath(
            (int) $backup['account_id'], $id, (string) $request->query('file', '')
        );
        if ($path === null) {
            app('session')->flash('error', 'Download not available for this backup.');
            return Response::redirect('/admin/backups');
        }
        audit('backup.downloaded','backup',$id,['file'=>basename($path)]);
        return Response::file($path, basename($path));
    }
    public function destroy(int $id): Response {
        $backup = app('db')->selectOne("SELECT * FROM `backups` WHERE `id`=?",[$id]);
        if (!$backup) {
            app('session')->flash('error','Backup not found.');
            return Response::redirect('/admin/backups');
        }
        $nameBase = BackupService::nameBase($backup['name']);
        app(BackupService::class)->delete($nameBase);
        if ($backup['type'] === 'databases' || $backup['type'] === 'full') {
            foreach (app(BackupService::class)->accountDatabases((int) $backup['account_id']) as $dbName) {
                app(BackupService::class)->dbDelete($nameBase . '.db.' . $dbName);
            }
        }
        app('db')->delete('backups','`id`=?',[$id]);
        audit('backup.deleted','backup',$id);
        app('session')->flash('success','Backup deleted.');
        return Response::redirect('/admin/backups');
    }
    public function restore(int $id, Request $request): Response {
        $backup = app('db')->selectOne("SELECT * FROM `backups` WHERE `id`=?",[$id]);
        if (!$backup) return Response::redirect('/admin/backups');
        if ($backup['status'] !== 'completed') { app('session')->flash('error','Only completed backups can be restored.'); return Response::redirect('/admin/backups'); }
        if ($request->input('confirm_restore') !== '1') { app('session')->flash('error','Confirm that restoring this backup may overwrite current files and databases.'); return Response::redirect('/admin/backups'); }
        $svc = app(BackupService::class);
        $nameBase = BackupService::nameBase($backup['name']);
        $ok = true;
        if ($backup['type'] === 'files' || $backup['type'] === 'full') {
            $ok = $svc->restore((int)$backup['account_id'], $nameBase) && $ok;
        }
        if ($backup['type'] === 'databases' || $backup['type'] === 'full') {
            foreach ($svc->accountDatabases((int) $backup['account_id']) as $dbName) {
                $ok = $svc->dbRestore($dbName, $nameBase . '.db.' . $dbName) && $ok;
            }
        }
        if ($ok) {
            event('BackupRestored', ['backup_id'=>$id]);
            audit('backup.restored','backup',$id);
        }
        app('session')->flash($ok ? 'success' : 'error', $ok ? 'Backup restored.' : 'Restore failed.');
        return Response::redirect('/admin/backups');
    }
}
