<?php
declare(strict_types=1);
namespace Modules\Databases\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Databases\Services\DatabaseService;

class AdminDatabaseController {
    private DatabaseService $svc;
    public function __construct(DatabaseService $svc) { $this->svc = $svc; }

    public function index(): Response {
        $dbs = app('db')->select(
            'SELECT d.*, a.`username` AS account_username FROM `hosting_databases` d LEFT JOIN `accounts` a ON a.`id` = d.`account_id` ORDER BY d.`name`'
        );
        $content = view('admin/databases/index', ['title' => 'Databases', 'databases' => $dbs], 'layouts/admin');
        return new Response((string) $content);
    }

    public function create(): Response {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);
        $content = view('admin/databases/form', ['title' => 'Add Database', 'accounts' => $accounts, 'old' => []], 'layouts/admin');
        return new Response((string) $content);
    }

    public function store(Request $request): Response {
        try { $data = $request->validate(['name' => 'required|max:64','account_id' => 'required|integer','username' => 'required|max:32','password' => 'required|min:10']); }
        catch (ValidationException $e) { return $this->backWithErrors($request, $e, '/admin/databases/create'); }

        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int)$data['account_id']]);
        if (!$account) { app('session')->flash('error', 'Invalid account.'); return Response::redirect('/admin/databases/create'); }

        $dbName = strtolower($account['username'] . '_' . $data['name']);
        if (app('db')->selectOne('SELECT `id` FROM `hosting_databases` WHERE `name` = ?', [$dbName])) {
            app('session')->flash('error', 'Database name already exists.');
            return Response::redirect('/admin/databases/create');
        }

        try {
            \Modules\Packages\Services\LimitService::require((int) $data['account_id'], 'databases');
            app(\Modules\Databases\Services\MysqlGovernorService::class)->assertDatabaseQuota((int) $data['account_id']);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/databases/create');
        }

        $dbUser = strtolower($account['username'] . '_' . $data['username']);
        $this->svc->create($dbName);
        $this->svc->createUser($dbUser, $data['password']);
        $this->svc->grantAll($dbUser, $dbName);

        app('db')->insert('hosting_databases', ['name' => $dbName,'username' => $dbUser,'password' => hash_password((string) $data['password']),'account_id' => (int)$data['account_id'],'status' => 'active','created_at' => date('Y-m-d H:i:s'),'updated_at' => date('Y-m-d H:i:s')]);
        $this->applyGovernor($dbUser);

        audit('database.created', 'database', $dbName);
        app('session')->flash('success', 'Database created.');
        return Response::redirect('/admin/databases');
    }

    public function show(int $id): Response {
        $db = app('db')->selectOne('SELECT d.*, a.`username` AS account_username FROM `hosting_databases` d LEFT JOIN `accounts` a ON a.`id` = d.`account_id` WHERE d.`id` = ?', [$id]);
        if (!$db) return Response::redirect('/admin/databases');
        $size = $this->svc->size($db['name']);
        $users = app('db')->select('SELECT * FROM `hosting_db_users` WHERE `database_id` = ?', [$id]);
        foreach ($users as &$u) { $u['hosts'] = $this->svc->remoteHosts((int) $u['id']); }
        unset($u);
        $content = view('admin/databases/show', ['title' => 'Database: ' . $db['name'], 'database' => $db, 'size' => $size, 'users' => $users, 'mysqlRemote' => DatabaseService::remoteEnabled()], 'layouts/admin');
        return new Response((string)$content);
    }

    public function destroy(int $id): Response {
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ?', [$id]);
        if (!$db) return Response::redirect('/admin/databases');
        $this->svc->delete($db['name']);
        $this->svc->deleteUser($db['username']);
        foreach (app('db')->select('SELECT `id`, `username` FROM `hosting_db_users` WHERE `database_id` = ?', [$id]) as $u) {
            $this->svc->deleteUser((string) ($u['username'] ?? ''));
            app('db')->delete('hosting_db_hosts', '`db_user_id` = ?', [(int) $u['id']]);
        }
        app('db')->delete('hosting_databases', '`id` = ?', [$id]);
        app('db')->delete('hosting_db_users', '`database_id` = ?', [$id]);
        audit('database.deleted', 'database', $db['name']);
        app('session')->flash('success', 'Database deleted.');
        return Response::redirect('/admin/databases');
    }

    public function addUser(int $id, Request $request): Response {
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ?', [$id]);
        if (!$db) return Response::redirect('/admin/databases');
        try { $data = $request->validate(['username' => 'required|max:32','password' => 'required|min:10']); }
        catch (ValidationException $e) { foreach ($e->errors() as $f => $ms) { foreach ($ms as $m) app('session')->pushError($f, $m); } return Response::redirect('/admin/databases/' . $id); }
        $user = strtolower($data['username']);
        $this->svc->createUser($user, $data['password']);
        $this->svc->grantAll($user, $db['name']);
        app('db')->insert('hosting_db_users', ['account_id' => $db['account_id'],'username' => $user,'password' => hash_password((string) $data['password']),'database_id' => $id,'created_at' => date('Y-m-d H:i:s'),'updated_at' => date('Y-m-d H:i:s')]);
        $this->applyGovernor($user);
        audit('database.user.created', 'database', $db['name'], ['user' => $user]);
        app('session')->flash('success', 'User added.');
        return Response::redirect('/admin/databases/' . $id);
    }

    public function deleteUser(int $id, int $userId): Response {
        $entry = app('db')->selectOne('SELECT * FROM `hosting_db_users` WHERE `id` = ? AND `database_id` = ?', [$userId, $id]);
        if (!$entry) return Response::redirect('/admin/databases');
        $this->svc->deleteUser($entry['username']);
        app('db')->delete('hosting_db_hosts', '`db_user_id` = ?', [$userId]);
        app('db')->delete('hosting_db_users', '`id` = ?', [$userId]);
        audit('database.user.deleted', 'database', $entry['username']);
        app('session')->flash('success', 'User deleted.');
        return Response::redirect('/admin/databases/' . $id);
    }

    public function addHost(int $id, int $userId, Request $request): Response {
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ?', [$id]);
        $entry = $db ? app('db')->selectOne('SELECT * FROM `hosting_db_users` WHERE `id` = ? AND `database_id` = ?', [$userId, $id]) : null;
        if (!$db || !$entry) return Response::redirect('/admin/databases');
        try {
            $data = $request->validate(['host' => 'required|max:255', 'password' => 'required|min:1']);
        } catch (ValidationException $e) { foreach ($e->errors() as $f => $ms) { foreach ($ms as $m) app('session')->pushError($f, $m); } return Response::redirect('/admin/databases/' . $id); }
        try {
            $host = DatabaseService::sanitizeHost((string) $data['host']);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Invalid host.');
            return Response::redirect('/admin/databases/' . $id);
        }
        if (app('db')->selectOne('SELECT `id` FROM `hosting_db_hosts` WHERE `db_user_id` = ? AND `host` = ?', [$userId, $host])) {
            app('session')->flash('error', 'Host already allowed.');
            return Response::redirect('/admin/databases/' . $id);
        }
        try {
            $this->svc->allowHost($entry['username'], $db['name'], $host, (string) $data['password']);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not allow host: ' . $e->getMessage());
            return Response::redirect('/admin/databases/' . $id);
        }
        app('db')->insert('hosting_db_hosts', ['db_user_id' => $userId, 'host' => $host, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->applyGovernor((string) $entry['username']);
        audit('database.host.allowed', 'database', $db['name'], ['user' => $entry['username'], 'host' => $host]);
        app('session')->flash('success', 'Remote host allowed.');
        return Response::redirect('/admin/databases/' . $id);
    }

    public function removeHost(int $id, int $userId, int $hostId): Response {
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ?', [$id]);
        $entry = $db ? app('db')->selectOne('SELECT * FROM `hosting_db_users` WHERE `id` = ? AND `database_id` = ?', [$userId, $id]) : null;
        $row = $entry ? app('db')->selectOne('SELECT * FROM `hosting_db_hosts` WHERE `id` = ? AND `db_user_id` = ?', [$hostId, $userId]) : null;
        if (!$db || !$entry || !$row) return Response::redirect('/admin/databases');
        try {
            $this->svc->revokeHost($entry['username'], $row['host']);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not revoke host: ' . $e->getMessage());
            return Response::redirect('/admin/databases/' . $id);
        }
        app('db')->delete('hosting_db_hosts', '`id` = ?', [$hostId]);
        audit('database.host.revoked', 'database', $db['name'], ['user' => $entry['username'], 'host' => $row['host']]);
        app('session')->flash('success', 'Remote host revoked.');
        return Response::redirect('/admin/databases/' . $id);
    }

    public function rotatePassword(int $id): Response {
        $db = app('db')->selectOne('SELECT * FROM `hosting_databases` WHERE `id` = ?', [$id]);
        if (!$db) return Response::redirect('/admin/databases');
        $newPass = bin2hex(random_bytes(12));
        $this->svc->rotatePassword($db['username'], $newPass);
        app('db')->update('hosting_databases', ['password' => hash_password((string) $newPass), 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$id]);
        audit('database.password.rotated', 'database', $db['name']);
        app('session')->flash('success', 'Password rotated. New password: ' . $newPass . ' (copy it now)');
        return Response::redirect('/admin/databases/' . $id);
    }

    /** Best-effort governor caps: a limit failure must never break DB creation. */
    private function applyGovernor(string $username): void {
        try { app(\Modules\Databases\Services\MysqlGovernorService::class)->applyForUsername($username); }
        catch (\Throwable $e) { app('logger')->warning('MySQL governor apply failed for ' . $username . ': ' . $e->getMessage()); }
    }

    private function backWithErrors(Request $r, ValidationException $e, string $path): Response {
        app('session')->flashInput($r->all());
        foreach ($e->errors() as $f => $ms) foreach ($ms as $m) app('session')->pushError($f, $m);
        return Response::redirect($path);
    }
}