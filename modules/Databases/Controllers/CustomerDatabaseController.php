<?php
declare(strict_types=1);
namespace Modules\Databases\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Databases\Services\DatabaseService;

class CustomerDatabaseController {
    private DatabaseService $svc;
    public function __construct(DatabaseService $svc) { $this->svc = $svc; }

    private function accountId(): ?int {
        $user = app('auth')->user(); if (!$user) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id` = ?', [$user->id]);
        return $row ? (int)$row['id'] : null;
    }

    public function index(): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        $dbs = app('db')->select('SELECT * FROM `hosting_databases` WHERE `account_id` = ? ORDER BY `name`', [$accountId]);
        $dbUsageBytes = null;
        $dbQuotaMb = 0;
        try {
            $gov = app(\Modules\Databases\Services\MysqlGovernorService::class);
            $dbUsageBytes = $gov->accountDatabaseBytes((int) $accountId);
            $dbQuotaMb = $gov->databaseQuotaMb((int) $accountId);
        } catch (\Throwable $e) {
            $dbUsageBytes = null;
        }
        $content = view('customer/databases/index', [
            'title' => 'My Databases',
            'databases' => $dbs,
            'dbUsageBytes' => $dbUsageBytes,
            'dbQuotaMb' => $dbQuotaMb,
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function create(): Response {
        $content = view('customer/databases/form', ['title' => 'Add Database', 'old' => []], 'layouts/customer');
        return new Response((string) $content);
    }

    public function store(Request $request): Response {
        $accountId = $this->accountId(); if (!$accountId) return Response::redirect('/panel');
        try { $data = $request->validate(['name' => 'required|max:64','username' => 'required|max:32','password' => 'required|min:10']); }
        catch (ValidationException $e) { return $this->backWithErrors($request, $e, '/panel/databases/create'); }
        // DatabaseService strips to [a-z0-9_] when provisioning; store only
        // what actually exists as the MySQL object (else row != principal).
        $rawName = strtolower(trim((string) $data['name']));
        $rawUser = strtolower(trim((string) $data['username']));
        if (!preg_match('/^[a-z0-9_]+$/', $rawName) || !preg_match('/^[a-z0-9_]+$/', $rawUser)) {
            app('session')->flash('error', 'Database and username may only contain lowercase letters, digits and underscores.');
            return Response::redirect('/panel/databases/create');
        }
        $account = app('db')->selectOne('SELECT `username` FROM `accounts` WHERE `id` = ?', [$accountId]);
        $dbName = strtolower($account['username'] . '_' . $data['name']);
        if (app('db')->selectOne('SELECT `id` FROM `hosting_databases` WHERE `name` = ?', [$dbName])) {
            app('session')->flash('error', 'Database name already exists.');
            return Response::redirect('/panel/databases/create');
        }
        try {
            \Modules\Packages\Services\LimitService::require($accountId, 'databases');
            app(\Modules\Databases\Services\MysqlGovernorService::class)->assertDatabaseQuota($accountId);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/databases/create');
        }
        $dbUser = strtolower($account['username'] . '_' . $rawUser);
        try {
            $this->svc->create($dbName); $this->svc->createUser($dbUser, $data['password']); $this->svc->grantAll($dbUser, $dbName);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not create database: ' . $e->getMessage());
            return Response::redirect('/panel/databases/create');
        }
        app('db')->insert('hosting_databases', ['name' => $dbName,'username' => $dbUser,'password' => hash_password((string) $data['password']),'account_id' => $accountId,'status' => 'active','created_at' => date('Y-m-d H:i:s'),'updated_at' => date('Y-m-d H:i:s')]);
        $this->applyGovernor($dbUser);
        audit('database.created', 'database', $dbName); app('session')->flash('success', 'Database created.');
        return Response::redirect('/panel/databases');
    }

    public function show(int $id): Response {
        $accountId = $this->accountId();
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if (!$db) return Response::redirect('/panel/databases');
        $users = app('db')->select('SELECT * FROM `hosting_db_users` WHERE `database_id` = ?', [$id]);
        foreach ($users as &$u) { $u['hosts'] = $this->svc->remoteHosts((int) $u['id']); }
        unset($u);
        $size = $this->svc->size($db['name']);
        $content = view('customer/databases/show', ['title' => 'Database: ' . $db['name'], 'database' => $db, 'size' => $size, 'users' => $users, 'mysqlRemote' => \Modules\Databases\Services\DatabaseService::remoteEnabled()], 'layouts/customer');
        return new Response((string)$content);
    }

    public function destroy(int $id): Response {
        $accountId = $this->accountId();
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if (!$db) return Response::redirect('/panel/databases');
        $this->svc->delete($db['name']); $this->svc->deleteUser($db['username']);
        foreach (app('db')->select('SELECT `id`, `username` FROM `hosting_db_users` WHERE `database_id` = ?', [$id]) as $u) {
            $this->svc->deleteUser((string) ($u['username'] ?? ''));
            app('db')->delete('hosting_db_hosts', '`db_user_id` = ?', [(int) $u['id']]);
        }
        app('db')->delete('hosting_databases', '`id` = ?', [$id]); app('db')->delete('hosting_db_users', '`database_id` = ?', [$id]);
        app('session')->flash('success', 'Database deleted.');
        return Response::redirect('/panel/databases');
    }

    public function addUser(int $id, Request $request): Response {
        $accountId = $this->accountId();
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if (!$db) return Response::redirect('/panel/databases');
        try { $data = $request->validate(['username' => 'required|max:32','password' => 'required|min:10']); }
        catch (ValidationException $e) { foreach ($e->errors() as $f => $ms) foreach ($ms as $m) app('session')->pushError($f, $m); return Response::redirect('/panel/databases/' . $id); }
        $username = strtolower(trim((string) $data['username']));
        // DatabaseService strips to [a-z0-9_] when creating the principal;
        // storing the raw input makes the row diverge from the real MySQL
        // user (orphans, undeletable, grant confusion). Validate + prefix.
        if (!preg_match('/^[a-z0-9_]+$/', $username)) {
            app('session')->flash('error', 'Username may only contain lowercase letters, digits and underscores.');
            return Response::redirect('/panel/databases/' . $id);
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        $username = ($account['username'] ?? 'db') . '_' . $username;
        if (app('db')->selectOne('SELECT `id` FROM `hosting_db_users` WHERE `username` = ?', [$username])) {
            app('session')->flash('error', 'That MySQL user already exists.');
            return Response::redirect('/panel/databases/' . $id);
        }
        try {
            $this->svc->createUser($username, $data['password']);
            $this->svc->grantAll($username, $db['name']);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not create MySQL user: ' . $e->getMessage());
            return Response::redirect('/panel/databases/' . $id);
        }
        app('db')->insert('hosting_db_users', ['account_id' => $accountId,'username' => $username,'password' => hash_password((string) $data['password']),'database_id' => $id,'created_at' => date('Y-m-d H:i:s'),'updated_at' => date('Y-m-d H:i:s')]);
        $this->applyGovernor($username);
        app('session')->flash('success', 'User added.');
        return Response::redirect('/panel/databases/' . $id);
    }

    public function deleteUser(int $id, int $userId): Response {
        $accountId = $this->accountId();
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if (!$db) return Response::redirect('/panel/databases');
        $entry = app('db')->selectOne('SELECT * FROM `hosting_db_users` WHERE `id` = ? AND `database_id` = ?', [$userId, $id]);
        if (!$entry) return Response::redirect('/panel/databases');
        try {
            $this->svc->deleteUser($entry['username']);
        } catch (\Throwable $e) {
            // Keep the row so the failed login is still visible/managed.
            app('session')->flash('error', 'Could not drop MySQL user: ' . $e->getMessage());
            return Response::redirect('/panel/databases/' . $id);
        }
        app('db')->delete('hosting_db_hosts', '`db_user_id` = ?', [$userId]);
        app('db')->delete('hosting_db_users', '`id` = ?', [$userId]);
        app('session')->flash('success', 'User deleted.');
        return Response::redirect('/panel/databases/' . $id);
    }

    public function addHost(int $id, int $userId, Request $request): Response {
        $accountId = $this->accountId();
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        $entry = $db ? app('db')->selectOne('SELECT * FROM `hosting_db_users` WHERE `id` = ? AND `database_id` = ?', [$userId, $id]) : null;
        if (!$db || !$entry) return Response::redirect('/panel/databases');
        try {
            $data = $request->validate(['host' => 'required|max:255', 'password' => 'required|min:1']);
        } catch (ValidationException $e) { foreach ($e->errors() as $f => $ms) foreach ($ms as $m) app('session')->pushError($f, $m); return Response::redirect('/panel/databases/' . $id); }
        try {
            $host = \Modules\Databases\Services\DatabaseService::sanitizeHost((string) $data['host']);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Invalid host.');
            return Response::redirect('/panel/databases/' . $id);
        }
        if (app('db')->selectOne('SELECT `id` FROM `hosting_db_hosts` WHERE `db_user_id` = ? AND `host` = ?', [$userId, $host])) {
            app('session')->flash('error', 'Host already allowed.');
            return Response::redirect('/panel/databases/' . $id);
        }
        try {
            $this->svc->allowHost($entry['username'], $db['name'], $host, (string) $data['password']);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not allow host: ' . $e->getMessage());
            return Response::redirect('/panel/databases/' . $id);
        }
        app('db')->insert('hosting_db_hosts', ['db_user_id' => $userId, 'host' => $host, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->applyGovernor((string) $entry['username']);
        app('session')->flash('success', 'Remote host allowed.');
        return Response::redirect('/panel/databases/' . $id);
    }

    public function removeHost(int $id, int $userId, int $hostId): Response {
        $accountId = $this->accountId();
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        $entry = $db ? app('db')->selectOne('SELECT * FROM `hosting_db_users` WHERE `id` = ? AND `database_id` = ?', [$userId, $id]) : null;
        $row = $entry ? app('db')->selectOne('SELECT * FROM `hosting_db_hosts` WHERE `id` = ? AND `db_user_id` = ?', [$hostId, $userId]) : null;
        if (!$db || !$entry || !$row) return Response::redirect('/panel/databases');
        try {
            $this->svc->revokeHost($entry['username'], $row['host']);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not revoke host: ' . $e->getMessage());
            return Response::redirect('/panel/databases/' . $id);
        }
        app('db')->delete('hosting_db_hosts', '`id` = ?', [$hostId]);
        app('session')->flash('success', 'Remote host revoked.');
        return Response::redirect('/panel/databases/' . $id);
    }

    public function rotatePassword(int $id, Request $request): Response {
        $accountId = $this->accountId();
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);
        if (!$db) return Response::redirect('/panel/databases');
        $newPass = bin2hex(random_bytes(12));
        $this->svc->rotatePassword($db['username'], $newPass);
        app('db')->update('hosting_databases', ['password' => hash_password((string) $newPass),'updated_at'=>date('Y-m-d H:i:s')], '`id` = ?', [$id]);
        app('session')->flash('success', 'Password rotated. New password: ' . $newPass . ' (copy it now)');
        return Response::redirect('/panel/databases/' . $id);
    }

    /** Best-effort governor caps: a limit failure must never break DB creation. */
    private function applyGovernor(string $username): void {
        try { app(\Modules\Databases\Services\MysqlGovernorService::class)->applyForUsername($username); }
        catch (\Throwable $e) { app('logger')->warning('MySQL governor apply failed for ' . $username . ': ' . $e->getMessage()); }
    }

    private function backWithErrors(Request $r, ValidationException $e, string $path): Response {        app('session')->flashInput($r->all());
        foreach ($e->errors() as $f => $ms) foreach ($ms as $m) app('session')->pushError($f, $m);
        return Response::redirect($path);
    }
}