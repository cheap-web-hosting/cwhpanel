<?php

declare(strict_types=1);

namespace Modules\Accounts\Services;

/**
 * AccountCreationService — provisions a hosting account end-to-end.
 *
 * Shared by the admin web form and the public API v1 (external billing
 * software). Mirrors what AccountController::store() used to do inline:
 * insert/reclaim the account row, assign the MCS category, ensure a panel
 * login for the contact email, run the privileged provisioner, set the
 * system password, create the main FTP login, allocate a dedicated IPv6
 * when IP management is on, provision the main domain (DNS + docroot +
 * vhost + auto-SSL job), and enable per-account nginx isolation.
 *
 * Business-rule failures throw \RuntimeException (caller decides how to
 * surface them); provisioning step failures are returned in `errors` and
 * never abort the account.
 */
class AccountCreationService
{
    /**
     * @param array $data validated shape: username, domain, email, password,
     *                    package_id (ints/strings both accepted)
     * @param string $via audit origin: 'web' (admin form) or 'api'
     * @return array{account_id:int, account:?array, reclaimed:bool,
     *               login_note:string, domain_note:string, isolation_note:string,
     *               provisioning:array, errors:array<int,string>}
     */
    public function create(array $data, string $via = 'web'): array
    {
        $db = app('db');

        $packageId = (int) ($data['package_id'] ?? 0);
        $package = $db->selectOne('SELECT * FROM `packages` WHERE `id` = ?', [$packageId]);
        if ($package === null) {
            throw new \RuntimeException('Selected package does not exist.');
        }

        $username = strtolower(trim((string) ($data['username'] ?? '')));
        if (!preg_match('/^[a-z0-9_-]{1,30}$/', $username)) {
            throw new \RuntimeException('Please enter a valid username.');
        }
        $domain = strtolower(trim((string) ($data['domain'] ?? '')));
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain)) {
            throw new \RuntimeException('Please enter a valid domain name.');
        }
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Please enter a valid email address.');
        }
        $password = (string) ($data['password'] ?? '');
        if (strlen($password) < (int) config('auth.min_password_length', 10)) {
            throw new \RuntimeException('The password must be at least ' . config('auth.min_password_length', 10) . ' characters.');
        }

        $existing = $db->selectOne('SELECT `id`, `status` FROM `accounts` WHERE `username` = ?', [$username]);
        if ($existing !== null && ($existing['status'] ?? '') !== 'terminated') {
            throw new \RuntimeException('Username already in use.');
        }

        if ($existing !== null) {
            // Reclaim a terminated row: same username, fresh details, back to
            // active. Old child rows were torn down by terminate, so they do
            // not revive with it.
            $accountId = (int) $existing['id'];
            $db->update('accounts', [
                'domain' => $domain,
                'email' => $email,
                'package_id' => $packageId,
                'status' => 'active',
                'home_dir' => '/home/' . $username,
                'mail_max_out_per_hour' => (int) ($package['mail_max_out_per_hour'] ?? 100),
                'mail_max_out_per_day' => (int) ($package['mail_max_out_per_day'] ?? 500),
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$accountId]);
            $reclaimed = true;
        } else {
            $accountId = $db->transaction(function ($db) use ($data, $domain, $username, $email, $packageId, $package) {
                return $db->insert('accounts', [
                    'username' => $username,
                    'domain' => $domain,
                    'email' => $email,
                    'package_id' => $packageId,
                    'status' => 'active',
                    'home_dir' => '/home/' . $username,
                    'mail_max_out_per_hour' => (int) ($package['mail_max_out_per_hour'] ?? 100),
                    'mail_max_out_per_day' => (int) ($package['mail_max_out_per_day'] ?? 500),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            });
            $reclaimed = false;
        }

        // MCS category (c1..c1023 wrapper; 0 = unlabeled, never assigned).
        $cat = $accountId <= 1023 ? $accountId : ((($accountId - 1) % 1023) + 1);
        $db->update('accounts', ['mcs_category' => $cat, 'suspend_state' => null], '`id` = ?', [$accountId]);

        // The form promises one credential for panel login + system account:
        // ensure a customer login exists for the contact email and link it.
        $loginNote = '';
        try {
            $loginUser = $db->selectOne('SELECT `id`, `is_admin` FROM `users` WHERE `email` = ?', [$email]);
            if ($loginUser === null) {
                $role = $db->selectOne("SELECT `id` FROM `roles` WHERE `name` = 'Customer'");
                if ($role === null) {
                    throw new \RuntimeException('Customer role missing.');
                }
                $loginId = $db->insert('users', [
                    'email' => $email,
                    'password' => hash_password($password),
                    'name' => $username,
                    'role_id' => (int) $role['id'],
                    'is_admin' => 0,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $db->update('accounts', ['user_id' => $loginId, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$accountId]);
                audit('user.created', 'user', $loginId, ['email' => $email, 'account_id' => $accountId, 'via' => $via]);
                $loginNote = ' Panel login created.';
            } elseif (!(bool) $loginUser['is_admin']) {
                $db->update('accounts', ['user_id' => (int) $loginUser['id'], 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$accountId]);
                $loginNote = ' Linked to the existing login.';
            } else {
                $loginNote = ' Email belongs to an admin login — not linked.';
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Panel login ensure failed for account ' . $accountId . ': ' . $e->getMessage());
            $loginNote = ' Panel login could not be created (see log).';
        }

        $account = $db->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);

        $provisioning = app(AccountProvisioner::class)->provision($account, $package);
        $errors = [];
        foreach ($provisioning as $r) {
            if (($r['status'] ?? '') === 'error') {
                $errors[] = (string) ($r['operation'] ?? 'unknown');
            }
        }

        // System password (best-effort, reported never fatal).
        if ($password !== '') {
            try {
                app('provisioning')->execute('system.password.set', [
                    'account' => $username,
                    'username' => $username,
                    'password' => $password,
                ]);
            } catch (\Throwable $e) {
                $errors[] = 'system.password.set: ' . $e->getMessage();
            }
        }

        // Main FTP account (respects package features/limits; never fatal).
        try {
            $ftpOn = app(\Modules\Packages\Services\FeatureService::class)->forPackage($packageId);
            if (!empty($ftpOn['ftp']) && (int) ($package['max_ftp_accounts'] ?? 0) > 0
                && $db->selectOne('SELECT `id` FROM `ftp_accounts` WHERE `username` = ?', [$username]) === null) {
                $db->insert('ftp_accounts', [
                    'account_id' => $accountId,
                    'username' => $username,
                    'password' => hash('sha256', $password),
                    'home_dir' => '/home/' . $username,
                    'quota_mb' => 1024,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                app(\Modules\Ftp\Services\FtpService::class)->setup();
                app(\Modules\Ftp\Services\FtpService::class)->syncUser($username, '/home/' . $username);
                audit('ftp.account.created', 'ftp_account', $username, ['via' => 'account-create']);
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Main FTP account ensure failed for account ' . $accountId . ': ' . $e->getMessage());
        }

        event('AccountCreated', ['account_id' => $accountId, 'username' => $username, 'package_id' => $packageId]);
        audit('account.created', 'account', $accountId, ['username' => $username, 'reclaimed' => $reclaimed, 'via' => $via]);

        // Dedicated IPv6 when the feature is on (best-effort, before DNS).
        try {
            $ipSvc = app(\Modules\Network\Services\IpAllocatorService::class);
            if ($ipSvc->enabled()) {
                $fresh = $db->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
                if ($fresh !== null && trim((string) ($fresh['ipv6_address'] ?? '')) === '') {
                    $ipSvc->allocateForAccount($fresh);
                }
            }
        } catch (\Throwable $e) {
            app('logger')->warning('IPv6 auto-allocate failed for account ' . $accountId . ': ' . $e->getMessage());
        }

        $domainNote = $this->provisionMainDomain($accountId, $username, $domain, $via);

        // Isolate every new account by default (nginx worker + MCS category).
        $isolationNote = '';
        try {
            $isolation = app(\Modules\Core\Services\NginxIsolationService::class);
            $fresh = $db->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
            if ($fresh !== null) {
                if (!$isolation->isEnabled($fresh)) {
                    $isolation->enable($fresh);
                    $db->update('accounts', ['nginx_worker_enabled' => 1, 'updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$accountId]);
                } else {
                    $isolation->startWorker($fresh);
                }
                $isolationNote = ' Isolated (nginx worker + MCS category).';
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Default isolation failed for account ' . $accountId . ': ' . $e->getMessage());
            $isolationNote = ' Isolation could not be enabled automatically (see log).';
        }

        return [
            'account_id' => $accountId,
            'account' => $db->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]),
            'reclaimed' => $reclaimed,
            'login_note' => $loginNote,
            'domain_note' => $domainNote,
            'isolation_note' => $isolationNote,
            'provisioning' => $provisioning,
            'errors' => $errors,
        ];
    }

    /**
     * Set up the account's main domain (domains row + docroot + DNS zone +
     * website + vhost via DomainService). Best-effort: returns a note,
     * never throws.
     */
    private function provisionMainDomain(int $accountId, string $username, string $domainName, string $via): string
    {
        $name = strtolower(trim($domainName));
        if ($name === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $name)) {
            return '';
        }
        try {
            if (app('db')->selectOne('SELECT `id` FROM `domains` WHERE `name` = ?', [$name]) !== null) {
                return ' Domain already exists — left as is.';
            }
            $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
            if ($account === null) {
                return '';
            }
            $id = app('db')->insert('domains', [
                'name' => $name,
                'account_id' => $accountId,
                'type' => 'main',
                'parent_domain_id' => null,
                'redirect_url' => null,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [$id]);
            app(\Modules\Domains\Services\DomainService::class)->provision($account, $domain);
            event('DomainCreated', ['domain_id' => $id, 'name' => $name]);
            audit('domain.created', 'domain', $id, ['name' => $name, 'type' => 'main', 'via' => $via]);
            return " Main domain {$name} set up and ready.";
        } catch (\Throwable $e) {
            app('logger')->warning('Main domain provisioning failed for account ' . $accountId . ': ' . $e->getMessage());
            return ' Main domain could not be set up automatically (see log).';
        }
    }
}
