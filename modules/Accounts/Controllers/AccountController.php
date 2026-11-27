<?php

declare(strict_types=1);

namespace Modules\Accounts\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Accounts\Services\AccountProvisioner;

class AccountController
{
    public function index(Request $request): Response
    {
        $db = app('db');
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND (a.`username` LIKE ? OR a.`domain` LIKE ? OR a.`email` LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        if ($status !== '' && in_array($status, ['active', 'suspended', 'terminated'], true)) {
            $where .= ' AND a.`status` = ?';
            $params[] = $status;
        }

        $accounts = $db->select(
            "SELECT a.*, p.`name` AS package_name, p.`disk_quota_mb`, p.`max_domains`
             FROM `accounts` a
             LEFT JOIN `packages` p ON p.`id` = a.`package_id`
             WHERE {$where}
             ORDER BY a.`username`",
            $params
        );

        $content = view('admin/accounts/index', [
            'title' => 'Accounts',
            'accounts' => $accounts,
            'search' => $search,
            'status' => $status,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        $packages = app('db')->select('SELECT * FROM `packages` WHERE `is_active` = 1 ORDER BY `name`');

        $content = view('admin/accounts/form', [
            'title' => 'Add Account',
            'account' => null,
            'old' => app('session')->get('_old', []),
            'packages' => $packages,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function store(Request $request): Response
    {
        try {
            $data = $request->validate([
                'username' => 'required|alpha_dash|max:30',
                'domain' => 'required|max:255',
                'email' => 'required|email',
                'package_id' => 'required|integer',
                'password' => 'required|min:' . config('auth.min_password_length', 10),
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/accounts/create');
        }

        try {
            $result = app(\Modules\Accounts\Services\AccountCreationService::class)->create($data, 'web');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            app('session')->flashInput($request->only(['username', 'domain', 'email', 'package_id']));
            return Response::redirect('/admin/accounts/create');
        }

        if ($result['errors'] !== []) {
            app('session')->flash('error', 'Account created, but some provisioning steps failed: ' . implode('; ', array_slice($result['errors'], 0, 3)));
            app('logger')->warning('Partial provisioning for account ' . $result['account_id'] . ': ' . implode('; ', $result['errors']));
        }

        app('session')->flash('success', ($result['reclaimed'] ? 'Account re-created.' : 'Account created.') . $result['login_note'] . $result['domain_note'] . $result['isolation_note']);

        return Response::redirect('/admin/accounts/' . $result['account_id']);
    }

    public function show(int $id): Response
    {
        $account = $this->findWithPackage($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        $logs = app('db')->select('SELECT * FROM `provisioning_logs` ORDER BY `id` DESC LIMIT 10');

        $ssh = app(\Modules\Security\Services\SshService::class);
        $agentError = '';
        try {
            $sshKeys = $ssh->keys($account['username']);
            $sshMode = $ssh->modeOrNull($account['username']);
            if ($sshMode === null) {
                $agentError = 'SSH state unavailable: the provisioning agent did not answer.';
            }
        } catch (\Throwable $e) {
            $sshKeys = [];
            $sshMode = null;
            $agentError = 'SSH state unavailable: ' . $e->getMessage();
        }

        $content = view('admin/accounts/show', [
            'title' => 'Account: ' . $account['username'],
            'account' => $account,
            'sshKeys' => $sshKeys,
            'sshShell' => $ssh->shell($account['username']),
            'sshEnabled' => $ssh->sshEnabled($account['username']),
            'sshMode' => $sshMode ?? 'unknown',
            'agentError' => $agentError,
            'has_system_user' => posix_getpwnam((string) $account['username']) !== false,
            'logs' => $logs,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function edit(int $id): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        $packages = app('db')->select('SELECT * FROM `packages` WHERE `is_active` = 1 ORDER BY `name`');

        $content = view('admin/accounts/form', [
            'title' => 'Edit Account',
            'account' => $account,
            'old' => app('session')->get('_old', []),
            'packages' => $packages,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function update(int $id, Request $request): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        try {
            $data = $request->validate([
                'domain' => 'required|max:255',
                'email' => 'required|email',
                'package_id' => 'required|integer',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/accounts/' . $id . '/edit');
        }

        $packageId = (int) $data['package_id'];

        $domain = strtolower(trim((string) $data['domain']));
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain)) {
            app('session')->flashInput($request->only(['domain', 'email', 'package_id']));
            app('session')->flash('error', 'Please enter a valid domain name.');
            return Response::redirect('/admin/accounts/' . $id . '/edit');
        }

        $package = app('db')->selectOne('SELECT * FROM `packages` WHERE `id` = ?', [$packageId]);

        if ($package === null) {
            app('session')->flashInput($request->only(['domain', 'email', 'package_id']));
            app('session')->flash('error', 'Selected package does not exist.');
            return Response::redirect('/admin/accounts/' . $id . '/edit');
        }

        app('db')->update('accounts', [
            'domain' => $domain,
            'email' => $data['email'],
            'package_id' => $packageId,
            'mail_max_out_per_hour' => (int) ($package['mail_max_out_per_hour'] ?? 100),
            'mail_max_out_per_day' => (int) ($package['mail_max_out_per_day'] ?? 500),
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);

        event('AccountUpdated', ['account_id' => $id, 'package_id' => $packageId]);
        audit('account.updated', 'account', $id);
        app('session')->flash('success', 'Account updated.');

        return Response::redirect('/admin/accounts/' . $id);
    }

    public function suspend(int $id): Response
    {
        return $this->transition($id, 'suspended', 'account.suspended', 'Account suspended.');
    }

    public function unsuspend(int $id): Response
    {
        return $this->transition($id, 'active', 'account.unsuspended', 'Account unsuspended.');
    }

    public function terminate(int $id): Response
    {
        return $this->transition($id, 'terminated', 'account.terminated', 'Account terminated.');
    }

    public function toggleNginxWorker(int $id): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        $isolation = app(\Modules\Core\Services\NginxIsolationService::class);
        $enabled = !$isolation->isEnabled($account);

        $result = $enabled ? $isolation->enable($account) : $isolation->disable($account);

        app('db')->update('accounts', [
            'nginx_worker_enabled' => $enabled ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);

        audit($enabled ? 'account.nginx_worker_enabled' : 'account.nginx_worker_disabled', 'account', $id, [
            'username' => $account['username'],
        ]);

        if (($result['status'] ?? '') === 'error') {
            app('session')->flash('error', 'Nginx worker ' . ($enabled ? 'enable' : 'disable') . ' failed: ' . ($result['output'] ?? ''));
        } else {
            app('session')->flash('success', 'Per-account nginx worker ' . ($enabled ? 'enabled' : 'disabled') . '.');
        }

        return Response::redirect('/admin/accounts/' . $id);
    }

    public function sshAddKey(int $id, Request $request): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        $result = app(\Modules\Security\Services\SshService::class)->addKey(
            $account['username'], (string) $request->input('key', '')
        );

        if ($result['ok']) {
            audit('ssh.key.added', 'account', $id, ['username' => $account['username']]);
            app('session')->flash('success', 'SSH key added.');
        } else {
            app('session')->flash('error', $result['error'] ?? 'Failed to add key.');
        }

        return Response::redirect('/admin/accounts/' . $id);
    }

    public function sshRemoveKey(int $id, int $index): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        $result = app(\Modules\Security\Services\SshService::class)->removeKey($account['username'], $index);

        if ($result['ok']) {
            audit('ssh.key.removed', 'account', $id, ['username' => $account['username']]);
            app('session')->flash('success', 'SSH key removed.');
        } else {
            app('session')->flash('error', $result['error'] ?? 'Failed to remove key.');
        }

        return Response::redirect('/admin/accounts/' . $id);
    }

    public function sshShell(int $id, Request $request): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        $mode = strtolower(trim((string) $request->input('mode', '')));
        if ($mode === '') {
            $mode = $request->input('enabled') ? 'full' : 'off';
        }
        $result = app(\Modules\Security\Services\SshService::class)->setMode($account['username'], $mode);

        if ($result['ok']) {
            $label = \Modules\Security\Services\SshService::MODE_LABELS[$mode] ?? $mode;
            audit('ssh.mode.' . $mode, 'account', $id, ['username' => $account['username']]);
            app('session')->flash('success', 'SSH access: ' . $label . '.');
        } else {
            app('session')->flash('error', $result['error'] ?? 'Failed to update SSH access.');
        }

        return Response::redirect('/admin/accounts/' . $id);
    }

    public function reprovision(int $id): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        $package = app('db')->selectOne('SELECT * FROM `packages` WHERE `id` = ?', [$account['package_id']]);
        $results = app(AccountProvisioner::class)->provision($account, $package ?: []);

        audit('account.reprovisioned', 'account', $id, ['operations' => count($results)]);

        if (AccountProvisioner::failures($results) > 0) {
            $failed = array_values(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'error'));
            app('session')->flash('error', 'Reprovision completed with failed steps: ' . $failed[0]['operation'] . ' — see log for details');
            app('logger')->warning('Reprovision partial failure for account ' . $id . ': ' . json_encode($failed));
        } else {
            app('session')->flash('success', 'Account reprovisioned (' . count($results) . ' operations).');
        }

        return Response::redirect('/admin/accounts/' . $id);
    }

    public function changePassword(int $id, Request $request): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        try {
            $data = $request->validate([
                'password' => 'required|min:' . config('auth.min_password_length', 10) . '|confirmed',
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    app('session')->pushError($field, $msg);
                }
            }
            return Response::redirect('/admin/accounts/' . $id);
        }

        if ($account['user_id'] !== null) {
            app('db')->update('users', ['password' => hash_password($data['password'])], '`id` = ?', [$account['user_id']]);
            // A forced reset is an incident-response action: every existing
            // session of that user must die, not just the password.
            app(\Modules\Authentication\Services\SessionService::class)
                ->revokeOthers((int) $account['user_id'], null);
        }

        audit('account.password_changed', 'account', $id);
        app('session')->flash('success', 'Password changed.');

        return Response::redirect('/admin/accounts/' . $id);
    }

    /**
     * Login as the account's linked customer (support impersonation).
     * Requires a linked non-admin login; nested/admin targets refused.
     */
    public function impersonate(int $id): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }
        if (empty($account['user_id'])) {
            app('session')->flash('error', 'No panel login is linked to this account.');
            return Response::redirect('/admin/accounts/' . $id);
        }
        try {
            app('auth')->impersonate((int) $account['user_id']);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/accounts/' . $id);
        }

        return Response::redirect('/panel');
    }

    /**
     * Reset the SYSTEM (SSH) password of the hosting account. Unlike
     * changePassword (panel login of the linked user), this works for every
     * provisioned account, linked or not.
     */
    public function changeSystemPassword(int $id, Request $request): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        try {
            $data = $request->validate([
                'password' => 'required|min:' . config('auth.min_password_length', 10) . '|confirmed',
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    app('session')->pushError($field, $msg);
                }
            }
            return Response::redirect('/admin/accounts/' . $id);
        }

        try {
            app('provisioning')->execute('system.password.set', [
                'account' => (string) $account['username'],
                'username' => (string) $account['username'],
                'password' => (string) $data['password'],
            ]);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'System password change failed: ' . $e->getMessage());
            return Response::redirect('/admin/accounts/' . $id);
        }

        audit('account.system_password_changed', 'account', $id);
        app('session')->flash('success', 'System password changed.');

        return Response::redirect('/admin/accounts/' . $id);
    }

    /** Toggle at-rest backup encryption for new backups of this account. */
    public function backupEncryptionToggle(int $id, Request $request): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        $on = (bool) $request->input('enabled');
        app('db')->update('accounts', [
            'backup_encrypted' => $on ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);
        audit('backup.encryption.toggled', 'account', $id, ['on' => $on]);
        app('session')->flash('success', $on ? 'New backups will be encrypted.' : 'New backups will be stored unencrypted.');

        return Response::redirect('/admin/accounts/' . $id);
    }

    private function transition(int $id, string $status, string $eventName, string $message): Response
    {
        $account = $this->find($id);

        if ($account === null) {
            return Response::redirect('/admin/accounts');
        }

        app('db')->update('accounts', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);

        $provisioner = app(AccountProvisioner::class);
        $suspender = app(\Modules\Accounts\Services\AccountSuspendService::class);

        if ($status === 'suspended') {
            // Full halt: shell lock + parked websites + stopped apps +
            // paused crons + held mailboxes + locked FTP logins (resume
            // state snapshotted).
            $held = $suspender->suspend($account);
            $message .= " ({$held['parked']} site(s) parked, {$held['apps']} app(s) stopped, {$held['crons']} cron(s) paused, {$held['mailboxes']} mailbox(es) held, {$held['ftp']} FTP login(s) locked.)";
            if (($held['errors'] ?? 0) > 0) {
                $message .= ' WARNING: ' . (int) $held['errors'] . ' resource(s) did not reach the suspended state (see log).';
            } elseif (($held['errors'] ?? 0) < 0) {
                $message .= ' WARNING: suspension completeness could not be verified.';
            }
        } elseif ($status === 'active') {
            $held = $suspender->unsuspend($account);
            $message .= " ({$held['parked']} site(s) restored, {$held['apps']} app(s) restarted, {$held['crons']} cron(s) resumed, {$held['mailboxes']} mailbox(es) restored, {$held['ftp']} FTP login(s) unlocked.)";
        } elseif ($status === 'terminated') {
            // Full teardown of child resources BEFORE the system user (and its
            // home) is deleted: apps, vhosts, DNS, mail, SSL, cron. Prevents a
            // re-created account inheriting the previous tenant's resources.
            $teardown = app(\Modules\Accounts\Services\AccountTeardownService::class)->purge($id, $account);
            $termResults = $provisioner->terminate($account['username']);
            $this->removeFtpLogins($id, $account);
            $this->releaseAccountIps($id, $account);

            if ($teardown['errors'] !== []) {
                $message .= ' Teardown completed with ' . count($teardown['errors']) . ' warning(s) (see log).';
            }
            if (AccountProvisioner::failures($termResults) > 0) {
                $termFailed = array_values(array_filter($termResults, fn ($r) => ($r['status'] ?? '') === 'error'));
                $message .= ' WARNING: ' . count($termFailed) . ' provisioning teardown step(s) failed (' . $termFailed[0]['operation'] . ' — see log).';
            }
        }

        event($eventName, ['account_id' => $id]);
        audit($eventName, 'account', $id, ['username' => $account['username']]);
        app('session')->flash('success', $message);

        return Response::redirect('/admin/accounts/' . $id);
    }

    private function find(int $id): ?array
    {
        return app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$id]);
    }

    /**
     * Remove per-row FTP system logins on terminate (files live inside the
     * account tree and die with it; the login entries must not linger as
     * orphaned system users). Rows are deleted with them.
     */
    private function removeFtpLogins(int $id, array $account): void
    {
        try {
            $ftp = app(\Modules\Ftp\Services\FtpService::class);
            foreach (app('db')->select('SELECT `id`, `username` FROM `ftp_accounts` WHERE `account_id` = ?', [$id]) as $row) {
                try {
                    $ftp->removeLogin((string) $row['username']);
                } catch (\Throwable $e) {
                    app('logger')->warning('Terminate FTP login remove failed for ' . $row['username'] . ': ' . $e->getMessage());
                }
                try {
                    app('db')->delete('ftp_accounts', '`id` = ?', [(int) $row['id']]);
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Terminate FTP cleanup failed for ' . $account['username'] . ': ' . $e->getMessage());
        }
    }

    /**
     * Release an account's dedicated IPs on terminate: unbind the v6,
     * free pool v4 rows back and clear website overrides, then converge
     * vhosts/DNS/mail (stale dedicated listens would fail the next
     * nginx reload). Best-effort — termination must never fail on this.
     */
    private function releaseAccountIps(int $id, array $account): void
    {
        try {
            $ips = app(\Modules\Network\Services\IpAllocatorService::class);
            $hadV6 = trim((string) ($account['ipv6_address'] ?? '')) !== '';
            $hadV4 = (int) (app('db')->selectOne(
                "SELECT COUNT(*) AS `n` FROM `websites` WHERE `account_id` = ? AND `ipv4_address` IS NOT NULL AND `ipv4_address` != ''",
                [$id]
            )['n'] ?? 0) > 0;
            if (!$hadV6 && !$hadV4) {
                return;
            }
            $ips->releaseForAccount($account);
            foreach (app('db')->select('SELECT * FROM `websites` WHERE `account_id` = ?', [$id]) as $site) {
                $prev = trim((string) ($site['ipv4_address'] ?? ''));
                if ($prev === '') {
                    continue;
                }
                $prow = app('db')->selectOne('SELECT * FROM `ip_pool` WHERE `address` = ?', [$prev]);
                if ($prow !== null) {
                    app('db')->update('ip_pool', [
                        'status' => 'free', 'website_id' => null, 'updated_at' => date('Y-m-d H:i:s'),
                    ], '`id` = ?', [(int) $prow['id']]);
                }
                app('db')->update('websites', [
                    'ipv4_address' => null, 'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [(int) $site['id']]);
            }
            $ips->applyAccountIps($id);
        } catch (\Throwable $e) {
            app('logger')->warning('IP release on terminate failed for account ' . $id . ': ' . $e->getMessage());
        }
    }

    private function findWithPackage(int $id): ?array
    {
        return app('db')->selectOne(
            'SELECT a.*, p.`name` AS package_name, p.`disk_quota_mb`, p.`bandwidth_mb`, p.`max_domains`,
                    p.`max_databases`, p.`max_email_accounts`, p.`max_cpu_percent`, p.`max_memory_mb`, p.`max_processes`
             FROM `accounts` a
             LEFT JOIN `packages` p ON p.`id` = a.`package_id`
             WHERE a.`id` = ?',
            [$id]
        );
    }

    private function backWithErrors(Request $request, ValidationException $e, string $path): Response
    {
        app('session')->flashInput($request->only(['username', 'domain', 'email', 'package_id']));
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $msg) {
                app('session')->pushError($field, $msg);
            }
        }

        return Response::redirect($path);
    }
}
