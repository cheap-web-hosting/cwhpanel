<?php
declare(strict_types=1);
namespace Modules\Backups\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Backups\Services\BackupService;

class CustomerBackupController {
    private function accountId(): ?int {
        $user = app('auth')->user(); if (!$user) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id`=?',[$user->id]);
        return $row ? (int)$row['id'] : null;
    }
    public function index(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $backups = app('db')->select("SELECT * FROM `backups` WHERE `account_id`=? ORDER BY `created_at` DESC",[$accountId]);
        $svc = app(BackupService::class);
        $copyRows = app('db')->select(
            'SELECT c.`backup_id`, c.`id` AS copy_id, c.`remote_path`, r.`name` AS remote_name
             FROM `backup_copies` c JOIN `backups` b ON b.`id` = c.`backup_id`
             LEFT JOIN `backup_remotes` r ON r.`id` = c.`remote_id`
             WHERE b.`account_id` = ? ORDER BY c.`id`',
            [$accountId]
        );
        $copies = [];
        foreach ($copyRows as $cr) {
            $copies[(int) $cr['backup_id']][] = $cr;
        }
        foreach ($backups as &$b) {
            $b['artifacts'] = $svc->artifacts($b);
            $b['copies'] = $copies[(int) $b['id']] ?? [];
            $b['local_files'] = array_column($b['artifacts'], 'file');
        }
        unset($b);
        $account = app('db')->selectOne('SELECT `backup_encrypted` FROM `accounts` WHERE `id` = ?', [$accountId]);
        $backupUsageBytes = null;
        $backupQuotaMb = 0;
        try {
            $backupUsageBytes = $svc->storedBytes((int) $accountId);
            $backupQuotaMb = $svc->backupQuotaMb((int) $accountId);
        } catch (\Throwable $e) {
            $backupUsageBytes = null;
        }
        $content = view('customer/backups/index', [
            'title' => 'Backups',
            'backups' => $backups,
            'encrypted' => !empty($account['backup_encrypted']),
            'backupUsageBytes' => $backupUsageBytes,
            'backupQuotaMb' => $backupQuotaMb,
        ], 'layouts/customer');
        return new Response((string)$content);
    }
    public function create(): Response {
        $content = view('customer/backups/form', ['title'=>'Create Backup'], 'layouts/customer');
        return new Response((string)$content);
    }

    /**
     * Toggle at-rest encryption for the customer's own new backups (existing
     * archives keep whatever form they were written in — magic bytes decide
     * the restore/download path, so mixed states are safe).
     */
    public function encryptionToggle(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel/backups');
        $on = (bool) $request->input('enabled');
        app('db')->update('accounts', ['backup_encrypted' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$accountId]);
        audit('backup.encryption.toggled', 'account', $accountId, ['on' => $on]);
        app('session')->flash('success', $on ? 'New backups will be encrypted.' : 'New backups will be stored unencrypted.');
        return Response::redirect('/panel/backups');
    }
    public function store(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $type = $request->input('type', 'full');
        if (!in_array($type, ['files', 'databases', 'full'], true)) {
            app('session')->flash('error','Invalid backup type.');
            return Response::redirect('/panel/backups/create');
        }
        $svc = app(BackupService::class);
        $acctRow = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `id`=?', [$accountId]);
        $nameBase = 'backup-' . preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($acctRow['username'] ?? 'acct'))) . '-' . date('Ymd-His');
        $fileStatus = 'success';
        $dbStatus = 'success';

        if ($type === 'files' || $type === 'full') {
            $fileStatus = $svc->createStatus($accountId, $nameBase);
        }
        if ($type === 'databases' || $type === 'full') {
            foreach ($svc->accountDatabases($accountId) as $dbName) {
                $s = $svc->dbCreate($dbName, $nameBase . '.db.' . $dbName);
                if ($s !== 'success') { $dbStatus = $s; }
            }
        }

        $ok = $fileStatus === 'success' && $dbStatus === 'success';
        $dryRun = $fileStatus === 'dry-run' || $dbStatus === 'dry-run';
        $id = app('db')->insert('backups', ['account_id'=>$accountId,'name'=>$nameBase.'.tar','type'=>$type,'size_bytes'=>0,'status'=>$ok ? 'completed' : ($dryRun ? 'dry-run' : 'failed'),'created_at'=>date('Y-m-d H:i:s')]);
        event('BackupCreated', ['backup_id'=>$id,'account_id'=>$accountId]);
        audit('backup.created','backup',$id);
        if ($ok) {
            app('session')->flash('success', 'Backup created.');
        } elseif ($dryRun) {
            app('session')->flash('error', 'Backup could not run because provisioning is disabled. Contact your administrator.');
        } else {
            app('session')->flash('error', 'Backup failed. Please contact your administrator if this continues.');
        }
        return Response::redirect('/panel/backups');
    }
    public function download(int $id, Request $request): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/backups');
        $path = app(BackupService::class)->downloadPath(
            $accountId, $id, (string) $request->query('file', '')
        );
        if ($path === null) {
            app('session')->flash('error', 'Download not available for this backup.');
            return Response::redirect('/panel/backups');
        }
        audit('backup.downloaded','backup',$id,['file'=>basename($path)]);
        return Response::file($path, basename($path));
    }
    public function destroy(int $id): Response {
        $accountId = $this->accountId();
        $backup = app('db')->selectOne("SELECT * FROM `backups` WHERE `id`=? AND `account_id`=?",[$id,$accountId]);
        if (!$backup) {
            app('session')->flash('error','Backup not found.');
            return Response::redirect('/panel/backups');
        }
        $nameBase = BackupService::nameBase($backup['name']);
        app(BackupService::class)->delete($nameBase);
        if ($backup['type'] === 'databases' || $backup['type'] === 'full') {
            foreach (app(BackupService::class)->accountDatabases((int) $backup['account_id']) as $dbName) {
                app(BackupService::class)->dbDelete($nameBase . '.db.' . $dbName);
            }
        }
        app('db')->delete('backups','`id`=? AND `account_id`=?',[$id,$accountId]);
        app('session')->flash('success','Backup deleted.');
        return Response::redirect('/panel/backups');
    }
    public function restore(int $id, Request $request): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/backups');
        $backup = app('db')->selectOne("SELECT * FROM `backups` WHERE `id`=? AND `account_id`=?",[$id,$accountId]);
        if (!$backup) return Response::redirect('/panel/backups');
        if ($backup['status'] !== 'completed') { app('session')->flash('error','Only completed backups can be restored.'); return Response::redirect('/panel/backups'); }
        if ($request->input('confirm_restore') !== '1') { app('session')->flash('error','Confirm that restoring this backup may overwrite current files and databases.'); return Response::redirect('/panel/backups'); }
        $svc = app(BackupService::class);
        $nameBase = BackupService::nameBase($backup['name']);
        $ok = true;
        if ($backup['type'] === 'files' || $backup['type'] === 'full') {
            $ok = $svc->restore($accountId, $nameBase) && $ok;
        }
        if ($backup['type'] === 'databases' || $backup['type'] === 'full') {
            foreach ($svc->accountDatabases($accountId) as $dbName) {
                $ok = $svc->dbRestore($dbName, $nameBase . '.db.' . $dbName) && $ok;
            }
        }
        if ($ok) {
            event('BackupRestored', ['backup_id'=>$id]);
            audit('backup.restored','backup',$id);
        }
        app('session')->flash($ok ? 'success' : 'error', $ok ? 'Backup restored.' : 'Restore failed.');
        return Response::redirect('/panel/backups');
    }

    /**
     * Fetch one remote artifact back into /var/backups, then land on the
     * normal per-file download for it.
     */
    public function fetchCopy(int $copyId): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/backups');
        try {
            $file = app(BackupService::class)->fetchCopy($accountId, $copyId);
        } catch (\RuntimeException $e) {
            app('session')->flash('error', 'Fetch failed: ' . $e->getMessage());
            return Response::redirect('/panel/backups');
        }
        $copy = app('db')->selectOne(
            'SELECT c.`backup_id` FROM `backup_copies` c JOIN `backups` b ON b.`id` = c.`backup_id`
             WHERE c.`id` = ? AND b.`account_id` = ?',
            [$copyId, $accountId]
        );
        app('session')->flash('success', 'Fetched ' . $file . ' from remote storage.');
        return Response::redirect('/panel/backups/' . (int) ($copy['backup_id'] ?? 0) . '/download?file=' . urlencode($file));
    }

    /**
     * Restore a backup whose local artifacts were pruned: fetch every
     * remote copy first, then run the standard restore flow.
     */
    public function restoreRemote(int $id, Request $request): Response {
        $accountId = $this->accountId();
        if (!$accountId) return Response::redirect('/panel/backups');
        $backup = app('db')->selectOne("SELECT * FROM `backups` WHERE `id`=? AND `account_id`=?",[$id,$accountId]);
        if (!$backup) return Response::redirect('/panel/backups');
        if ($backup['status'] !== 'completed') { app('session')->flash('error','Only completed backups can be restored.'); return Response::redirect('/panel/backups'); }
        if ($request->input('confirm_restore') !== '1') { app('session')->flash('error','Confirm that restoring this backup may overwrite current files and databases.'); return Response::redirect('/panel/backups'); }
        $svc = app(BackupService::class);
        try {
            foreach (app('db')->select('SELECT `id` FROM `backup_copies` WHERE `backup_id` = ?', [$id]) as $c) {
                $svc->fetchCopy($accountId, (int) $c['id']);
            }
        } catch (\RuntimeException $e) {
            app('session')->flash('error', 'Fetch from remote failed: ' . $e->getMessage());
            return Response::redirect('/panel/backups');
        }
        return $this->doRestore($accountId, $backup);
    }

    private function doRestore(int $accountId, array $backup): Response {
        $svc = app(BackupService::class);
        $nameBase = BackupService::nameBase($backup['name']);
        $ok = true;
        if ($backup['type'] === 'files' || $backup['type'] === 'full') {
            $ok = $svc->restore($accountId, $nameBase) && $ok;
        }
        if ($backup['type'] === 'databases' || $backup['type'] === 'full') {
            foreach ($svc->accountDatabases($accountId) as $dbName) {
                $ok = $svc->dbRestore($dbName, $nameBase . '.db.' . $dbName) && $ok;
            }
        }
        if ($ok) {
            event('BackupRestored', ['backup_id'=>(int) $backup['id']]);
            audit('backup.restored','backup',(int) $backup['id']);
        }
        app('session')->flash($ok ? 'success' : 'error', $ok ? 'Backup restored.' : 'Restore failed.');
        return Response::redirect('/panel/backups');
    }

    // ------------------------------------------------- schedules + remotes

    public function schedules(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $schedules = app('db')->select('SELECT s.*, r.`name` AS remote_name FROM `backup_schedules` s LEFT JOIN `backup_remotes` r ON r.`id` = s.`remote_id` WHERE s.`account_id` = ? ORDER BY s.`id`', [$accountId]);
        $remotes = app('db')->select('SELECT * FROM `backup_remotes` WHERE `account_id` = ? ORDER BY `name`', [$accountId]);
        $content = view('customer/backups/schedules', ['title' => 'Backup schedules', 'schedules' => $schedules, 'remotes' => $remotes, 'frequencies' => BackupService::FREQUENCIES, 'types' => BackupService::TYPES], 'layouts/customer');
        return new Response((string)$content);
    }

    public function scheduleStore(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        try {
            $data = $request->validate(['name' => 'required|max:64', 'frequency' => 'required', 'type' => 'required', 'keep_local' => 'required|integer', 'keep_remote' => 'required|integer']);
        } catch (ValidationException $e) { return $this->backWithErrors($request, $e, '/panel/backups/schedules'); }
        if (!isset(BackupService::FREQUENCIES[$data['frequency']]) || !in_array($data['type'], BackupService::TYPES, true)) {
            app('session')->flash('error', 'Invalid frequency or type.');
            return Response::redirect('/panel/backups/schedules');
        }
        $keepLocal = max(1, min(90, (int) $data['keep_local']));
        $keepRemote = max(0, min(365, (int) $data['keep_remote']));
        $remoteId = (int) $request->input('remote_id', 0);
        if ($remoteId > 0) {
            $remote = app('db')->selectOne('SELECT `id` FROM `backup_remotes` WHERE `id` = ? AND `account_id` = ?', [$remoteId, $accountId]);
            if ($remote === null) {
                app('session')->flash('error', 'Unknown remote storage.');
                return Response::redirect('/panel/backups/schedules');
            }
        } else {
            $remoteId = null;
        }
        $now = time();
        $id = app('db')->insert('backup_schedules', [
            'account_id' => $accountId, 'name' => trim((string) $data['name']),
            'frequency' => $data['frequency'], 'type' => $data['type'],
            'keep_local' => $keepLocal, 'remote_id' => $remoteId, 'keep_remote' => $keepRemote,
            'enabled' => $request->input('enabled', '1') === '1' ? 1 : 0,
            'last_run_at' => 0, 'next_run_at' => BackupService::nextRun($data['frequency'], $now),
            'last_status' => null, 'last_error' => null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        audit('backup.schedule.created', 'backup_schedule', $id);
        app('session')->flash('success', 'Backup schedule created.');
        return Response::redirect('/panel/backups/schedules');
    }

    public function scheduleToggle(int $id): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $sched = app('db')->selectOne('SELECT * FROM `backup_schedules` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if ($sched === null) return Response::redirect('/panel/backups/schedules');
        app('db')->update('backup_schedules', [
            'enabled' => empty($sched['enabled']) ? 1 : 0,
            'next_run_at' => empty($sched['enabled']) ? BackupService::nextRun($sched['frequency'], time()) : (int) $sched['next_run_at'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);
        app('session')->flash('success', empty($sched['enabled']) ? 'Schedule enabled.' : 'Schedule paused.');
        return Response::redirect('/panel/backups/schedules');
    }

    public function scheduleDestroy(int $id): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        app('db')->delete('backup_schedules', '`id` = ? AND `account_id` = ?', [$id, $accountId]);
        app('session')->flash('success', 'Schedule deleted (existing backups are kept).');
        return Response::redirect('/panel/backups/schedules');
    }

    public function scheduleRun(int $id): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $sched = app('db')->selectOne('SELECT * FROM `backup_schedules` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if ($sched === null) return Response::redirect('/panel/backups/schedules');
        app('db')->insert('jobs', [
            'type' => 'backup.scheduled', 'status' => 'pending',
            'payload' => json_encode(['schedule_id' => $id, 'manual' => true]),
            'result' => null, 'attempts' => 0, 'max_attempts' => 2,
            'available_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        audit('backup.schedule.run', 'backup_schedule', $id);
        app('session')->flash('success', 'Backup queued — it runs in the background.');
        return Response::redirect('/panel/backups/schedules');
    }

    public function remotes(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $remotes = app('db')->select('SELECT * FROM `backup_remotes` WHERE `account_id` = ? ORDER BY `name`', [$accountId]);
        foreach ($remotes as &$r) {
            unset($r['secret_encrypted']);
        }
        unset($r);
        $content = view('customer/backups/remotes', ['title' => 'Remote storage', 'remotes' => $remotes], 'layouts/customer');
        return new Response((string)$content);
    }

    public function remoteStore(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        try {
            $data = $request->validate([
                'name' => 'required|max:64', 'endpoint' => 'required|max:255',
                'bucket' => 'required|max:128', 'region' => 'max:32',
                'path_prefix' => 'max:128', 'access_key' => 'required|max:255',
                'secret' => 'required|max:500',
            ]);
        } catch (ValidationException $e) { return $this->backWithErrors($request, $e, '/panel/backups/remotes'); }
        try {
            $remote = $this->buildRemoteRow($accountId, $data);
            // Customers may only point remotes at public https endpoints. The
            // loopback-http exemption exists for the hermetic test stub and
            // would otherwise let any customer make the root worker issue
            // requests to loopback services (probe / write-to-disk).
            $user = app('auth')->user();
            $isAdmin = $user !== null && !empty($user->isAdmin);
            if (!$isAdmin) {
                $scheme = (string) parse_url($remote['endpoint'], PHP_URL_SCHEME);
                $host = (string) parse_url($remote['endpoint'], PHP_URL_HOST);
                $loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
                    || str_starts_with($host, '127.')
                    || str_ends_with(strtolower($host), '.localhost');
                if ($scheme !== 'https' || $loopback) {
                    throw new \RuntimeException('Remote endpoint must be https and point at a public host.');
                }
            }
            $client = new \Modules\Backups\Services\S3Client([
                'endpoint' => $remote['endpoint'], 'bucket' => $remote['bucket'],
                'region' => $remote['region'], 'key' => $remote['access_key'],
                'secret' => (string) $data['secret'],
            ]);
            unset($client);
        } catch (\RuntimeException $e) {
            app('session')->flash('error', 'Remote rejected: ' . $e->getMessage());
            return Response::redirect('/panel/backups/remotes');
        }
        try {
            $secret = \Modules\Backups\Services\RemoteSecret::seal((string) $data['secret']);
        } catch (\RuntimeException $e) {
            app('session')->flash('error', 'Could not protect the secret: ' . $e->getMessage());
            return Response::redirect('/panel/backups/remotes');
        }
        $id = app('db')->insert('backup_remotes', [
            'account_id' => $accountId, 'name' => trim((string) $data['name']), 'driver' => 's3',
            'endpoint' => $remote['endpoint'], 'bucket' => $remote['bucket'], 'region' => $remote['region'],
            'path_prefix' => $remote['path_prefix'], 'access_key' => $remote['access_key'],
            'secret_encrypted' => $secret, 'status' => 'untested',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        audit('backup.remote.created', 'backup_remote', $id);
        app('session')->flash('success', 'Remote storage added. Test the connection before relying on it.');
        return Response::redirect('/panel/backups/remotes');
    }

    public function remoteTest(int $id): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel/backups');
        $remote = app('db')->selectOne('SELECT * FROM `backup_remotes` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if ($remote === null) return Response::redirect('/panel/backups/remotes');
        try {
            $summary = app(BackupService::class)->testRemote($remote);
            app('db')->update('backup_remotes', ['status' => 'ok', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$id]);
            app('session')->flash('success', 'Connection works: ' . $summary . '.');
        } catch (\Throwable $e) {
            app('db')->update('backup_remotes', ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$id]);
            app('session')->flash('error', 'Connection failed: ' . $e->getMessage());
        }
        return Response::redirect('/panel/backups/remotes');
    }

    public function remoteDestroy(int $id): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $remote = app('db')->selectOne('SELECT * FROM `backup_remotes` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if ($remote !== null) {
            $used = app('db')->selectOne('SELECT `id` FROM `backup_schedules` WHERE `remote_id` = ? AND `account_id` = ? LIMIT 1', [$id, $accountId]);
            if ($used !== null) {
                app('session')->flash('error', 'Remote is used by a schedule — remove it from the schedule first.');
                return Response::redirect('/panel/backups/remotes');
            }
            app('db')->delete('backup_copies', '`remote_id` = ?', [$id]);
            app('db')->delete('backup_remotes', '`id` = ?', [$id]);
            audit('backup.remote.deleted', 'backup_remote', $id);
            app('session')->flash('success', 'Remote removed (objects already uploaded stay on the remote).');
        }
        return Response::redirect('/panel/backups/remotes');
    }

    /** Normalize + validate remote fields shared by store (edit reuses it later). */
    private function buildRemoteRow(int $accountId, array $data): array
    {
        $endpoint = rtrim(trim((string) $data['endpoint']), '/');
        $bucket = trim((string) $data['bucket']);
        $region = trim((string) ($data['region'] ?? '')) !== '' ? trim((string) $data['region']) : 'us-east-1';
        $prefix = trim(trim((string) ($data['path_prefix'] ?? '')), '/');
        if (!preg_match('#^https?://[^/\s]+$#', $endpoint)) {
            throw new \RuntimeException('Endpoint must be a plain https://host URL.');
        }
        // SSRF guard: a customer must not be able to point the panel at
        // loopback/private/link-local hosts (internal services, metadata).
        // Applied at store time; S3Client still re-checks the scheme. Admin
        // (and direct S3Client users) are unaffected.
        $ssrf = \Modules\Api\Services\WebhookPolicy::checkUrl($endpoint);
        if ($ssrf !== null) {
            throw new \RuntimeException('Endpoint rejected: ' . $ssrf);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new \RuntimeException('Bucket name is invalid.');
        }
        if ($prefix !== '' && !preg_match('#^[A-Za-z0-9._\-/]+$#', $prefix)) {
            throw new \RuntimeException('Path prefix contains invalid characters.');
        }
        $dup = app('db')->selectOne('SELECT `id` FROM `backup_remotes` WHERE `account_id` = ? AND `name` = ?', [$accountId, trim((string) $data['name'])]);
        if ($dup !== null) {
            throw new \RuntimeException('A remote with that name already exists.');
        }
        return ['endpoint' => $endpoint, 'bucket' => $bucket, 'region' => $region, 'path_prefix' => $prefix, 'access_key' => trim((string) $data['access_key'])];
    }

    private function backWithErrors(Request $r, ValidationException $e, string $path): Response {
        $input = $r->all();
        unset($input['secret']); // never persist the S3 secret in session flash
        app('session')->flashInput($input);
        $first = null;
        foreach ($e->errors() as $f => $ms) foreach ($ms as $m) {
            app('session')->pushError($f, $m);
            $first ??= $m;
        }
        // Field errors render nowhere yet (panel-wide gap) — flash the first
        // so validation failures are never silent.
        if ($first !== null) {
            app('session')->flash('error', $first);
        }
        return Response::redirect($path);
    }
}
