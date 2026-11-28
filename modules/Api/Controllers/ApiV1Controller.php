<?php

declare(strict_types=1);

namespace Modules\Api\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;

/**
 * ApiV1Controller — public API v1 for external integrations (billing
 * software etc.). Every route is authenticated by the `auth.api_key`
 * middleware (Bearer token → api_keys table → owning user) and API access
 * is administrator-only: non-admin key owners are refused with 403.
 * Admin keys create/suspend/terminate accounts.
 *
 * Response envelope:
 *   {"ok": true, "data": ...}
 *   {"ok": false, "error": "..."}        (or "errors": [...] for validation)
 */
class ApiV1Controller
{
    private function key(): array
    {
        return (array) app('api_key');
    }

    private function user(): array
    {
        return (array) app('api_user');
    }

    private function isAdmin(): bool
    {
        return (bool) ($this->user()['is_admin'] ?? 0);
    }

    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status);
    }

    private function ok($data, int $status = 200): Response
    {
        return $this->json(['ok' => true, 'data' => $data], $status);
    }

    private function fail(string $error, int $status = 400): Response
    {
        return $this->json(['ok' => false, 'error' => $error], $status);
    }

    private function requireAdmin(): ?Response
    {
        if (!$this->isAdmin()) {
            return $this->fail('This operation requires an administrator API key.', 403);
        }
        return null;
    }

    /** GET /api/v1/packages */
    public function packages(): Response
    {
        $rows = app('db')->select(
            'SELECT `id`, `name`, `slug`, `description`, `disk_quota_mb`, `bandwidth_mb`,
                    `max_domains`, `max_subdomains`, `max_databases`, `max_email_accounts`,
                    `max_ftp_accounts`, `max_cron_jobs`, `max_nodejs_apps`, `max_python_apps`,
                    `max_cpu_percent`, `max_memory_mb`, `max_processes`
             FROM `packages` WHERE `is_active` = 1 ORDER BY `name`'
        );
        return $this->ok($rows);
    }

    /** POST /api/v1/accounts */
    public function createAccount(Request $request): Response
    {
        if (($blocked = $this->requireAdmin()) !== null) {
            return $blocked;
        }

        $data = $request->all();
        if ($data === []) {
            $json = $request->json() ?: [];
            $data = is_array($json) ? $json : [];
        }
        try {
            $result = app(\Modules\Accounts\Services\AccountCreationService::class)->create($data, 'api');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $account = $result['account'] ?? [];
        return $this->ok([
            'account_id' => $result['account_id'],
            'username' => $account['username'] ?? null,
            'domain' => $account['domain'] ?? null,
            'email' => $account['email'] ?? null,
            'package_id' => isset($account['package_id']) ? (int) $account['package_id'] : null,
            'status' => $account['status'] ?? 'active',
            'reclaimed' => $result['reclaimed'],
            'provisioning_errors' => $result['errors'],
        ], $result['errors'] === [] ? 201 : 202);
    }

    /** GET /api/v1/accounts/{username} */
    public function account(string $username): Response
    {
        // Ownership first: checking existence before authorization turns this
        // into a hosting-username enumeration oracle (404 = exists, 403 = not
        // yours) for any customer API key.
        if (!$this->isAdmin()) {
            $mine = app('db')->selectOne(
                'SELECT `id` FROM `accounts` WHERE `username` = ? AND `user_id` = ?',
                [$username, (int) $this->user()['id']]
            );
            if ($mine === null) {
                return $this->fail('Account not found.', 404);
            }
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `username` = ?', [$username]);
        if ($account === null) {
            return $this->fail('Account not found.', 404);
        }

        $id = (int) $account['id'];
        $package = app('db')->selectOne('SELECT `id`, `name`, `slug` FROM `packages` WHERE `id` = ?', [(int) $account['package_id']]);
        $counts = [];
        foreach ([
            'domains' => 'domains',
            'websites' => 'websites',
            'databases' => 'hosting_databases',
            'mailboxes' => 'mailboxes',
            'ftp_accounts' => 'ftp_accounts',
            'cron_jobs' => 'cron_jobs',
            'applications' => 'applications',
        ] as $label => $table) {
            $counts[$label] = (int) app('db')->scalar("SELECT COUNT(*) FROM `{$table}` WHERE `account_id` = ?", [$id]);
        }

        return $this->ok([
            'account_id' => $id,
            'username' => $account['username'],
            'domain' => $account['domain'],
            'email' => $account['email'],
            'package' => $package,
            'status' => $account['status'],
            'home_dir' => $account['home_dir'],
            'ipv6_address' => $account['ipv6_address'] ?? null,
            'nginx_worker_enabled' => (bool) ($account['nginx_worker_enabled'] ?? 0),
            'created_at' => $account['created_at'],
            'counts' => $counts,
        ]);
    }

    /** POST /api/v1/accounts/{username}/suspend */
    public function suspendAccount(string $username): Response
    {
        if (($blocked = $this->requireAdmin()) !== null) {
            return $blocked;
        }
        $account = $this->findActive($username);
        if ($account === null) {
            return $this->fail('Account not found.', 404);
        }
        if (($account['status'] ?? '') === 'suspended') {
            return $this->ok(['username' => $username, 'status' => 'suspended', 'already' => true]);
        }
        app('db')->update('accounts', ['status' => 'suspended', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $account['id']]);
        $held = app(\Modules\Accounts\Services\AccountSuspendService::class)->suspend($account);
        audit('account.suspended', 'account', (int) $account['id'], ['username' => $username, 'via' => 'api']);
        return $this->ok(['username' => $username, 'status' => 'suspended', 'parked' => $held['parked'] ?? 0, 'apps_stopped' => $held['apps'] ?? 0]);
    }

    /** POST /api/v1/accounts/{username}/unsuspend */
    public function unsuspendAccount(string $username): Response
    {
        if (($blocked = $this->requireAdmin()) !== null) {
            return $blocked;
        }
        $account = $this->findActive($username);
        if ($account === null) {
            return $this->fail('Account not found.', 404);
        }
        if (($account['status'] ?? '') === 'active') {
            return $this->ok(['username' => $username, 'status' => 'active', 'already' => true]);
        }
        app('db')->update('accounts', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $account['id']]);
        $held = app(\Modules\Accounts\Services\AccountSuspendService::class)->unsuspend($account);
        audit('account.unsuspended', 'account', (int) $account['id'], ['username' => $username, 'via' => 'api']);
        return $this->ok(['username' => $username, 'status' => 'active', 'restored' => $held['parked'] ?? 0, 'apps_restarted' => $held['apps'] ?? 0]);
    }

    /** POST /api/v1/accounts/{username}/terminate */
    public function terminateAccount(string $username): Response
    {
        if (($blocked = $this->requireAdmin()) !== null) {
            return $blocked;
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `username` = ?', [$username]);
        if ($account === null) {
            return $this->fail('Account not found.', 404);
        }
        $id = (int) $account['id'];

        $teardown = app(\Modules\Accounts\Services\AccountTeardownService::class)->purge($id, $account);
        $termResults = app(\Modules\Accounts\Services\AccountProvisioner::class)->terminate($account['username']);
        $this->removeFtpLogins($id);
        try {
            app(\Modules\Network\Services\IpAllocatorService::class)->releaseForAccount($account);
        } catch (\Throwable $e) {
            app('logger')->warning('IP release on API terminate failed for ' . $username . ': ' . $e->getMessage());
        }
        app('db')->update('accounts', ['status' => 'terminated', 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$id]);

        $terminateErrors = [];
        foreach ($termResults as $r) {
            if (($r['status'] ?? '') === 'error') {
                $terminateErrors[] = (string) ($r['operation'] ?? 'unknown');
            }
        }
        $errors = array_merge($teardown['errors'], $terminateErrors);

        audit('account.terminated', 'account', $id, ['username' => $username, 'via' => 'api']);
        return $this->ok([
            'username' => $username,
            'status' => 'terminated',
            'errors' => $errors,
        ], $errors === [] ? 200 : 202);
    }

    private function findActive(string $username): ?array
    {
        return app('db')->selectOne('SELECT * FROM `accounts` WHERE `username` = ?', [$username]);
    }

    private function removeFtpLogins(int $accountId): void
    {
        try {
            $ftp = app(\Modules\Ftp\Services\FtpService::class);
            foreach (app('db')->select('SELECT `id`, `username` FROM `ftp_accounts` WHERE `account_id` = ?', [$accountId]) as $row) {
                try {
                    $ftp->removeLogin((string) $row['username']);
                } catch (\Throwable $e) {
                    app('logger')->warning('API terminate FTP login remove failed for ' . $row['username'] . ': ' . $e->getMessage());
                }
                try {
                    app('db')->delete('ftp_accounts', '`id` = ?', [(int) $row['id']]);
                } catch (\Throwable $e) {
                    app('logger')->warning('API terminate FTP row delete failed for ' . $row['username'] . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            app('logger')->warning('API terminate FTP cleanup failed for account ' . $accountId . ': ' . $e->getMessage());
        }
    }
}
