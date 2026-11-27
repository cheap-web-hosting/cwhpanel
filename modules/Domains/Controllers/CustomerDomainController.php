<?php

declare(strict_types=1);

namespace Modules\Domains\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;

class CustomerDomainController extends DomainControllerBase
{
    protected function areaPrefix(): string
    {
        return '/panel';
    }

    protected function layout(): string
    {
        return 'layouts/customer';
    }

    protected function viewArea(string $template): string
    {
        return 'customer/' . $template;
    }

    private function accountId(): ?int
    {
        $user = app('auth')->user();
        if ($user === null) return null;

        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id` = ?', [$user->id]);
        return $row !== null ? (int) $row['id'] : null;
    }

    public function index(): Response
    {
        $accountId = $this->accountId();

        if ($accountId === null) {
            return Response::redirect('/panel');
        }

        $domains = app('db')->select('SELECT * FROM `domains` WHERE `account_id` = ? ORDER BY `name`', [$accountId]);

        $content = view('customer/domains/index', [
            'title' => 'My Domains',
            'domains' => $domains,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        $content = view('customer/domains/main', [
            'title' => 'Add Domain',
            'old' => [],
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    /** Redirect the legacy "add domain" path to the addon-domain form. */
    public function createRedirect(): Response
    {
        return Response::redirect('/panel/domains/create/addon');
    }

    public function createAddon(): Response
    {
        $content = view('customer/domains/addon', [
            'title' => 'Add Addon Domain',
            'old' => [],
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function createParked(): Response
    {
        $content = view('customer/domains/parked', [
            'title' => 'Add Parked Domain',
            'old' => [],
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function createSubdomain(): Response
    {
        $accountId = $this->accountId();
        $parentDomains = app('db')->select('SELECT `id`,`name`,`type` FROM `domains` WHERE `account_id` = ? ORDER BY `name`', [$accountId]);

        $content = view('customer/domains/subdomain', [
            'title' => 'Add Subdomain',
            'old' => [],
            'parentDomains' => $parentDomains,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function store(Request $request): Response
    {
        $accountId = $this->accountId();

        if ($accountId === null) {
            return Response::redirect('/panel');
        }

        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        if ($account === null) {
            return Response::redirect('/panel');
        }

        $type = strtolower((string) ($request->input('type', 'main')));

        // Customers may create addon/parked/subdomain only: `main` is the
        // account's primary domain (it shares public_html), so accepting the
        // hidden value lets a customer mint extra "primary" domains and break
        // the type invariants the web/mail/DNS/deprovision flows rely on.
        if (!in_array($type, ['addon', 'parked', 'subdomain'], true)) {
            app('session')->flash('error', 'Invalid domain type.');
            app('session')->flashInput($request->all());
            return Response::redirect($this->createPathFor('addon'));
        }

        try {
            $data = $request->validate([
                'type' => 'required',
                'name' => 'max:255',
                'subdomain' => 'max:63',
                'parent_domain_id' => 'integer',
                'redirect_url' => 'max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, $this->createPathFor($type));
        }

        $parent = null;
        $redirectUrl = '';

        if ($type === 'subdomain') {
            $parent = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ? AND `account_id` = ?', [(int) ($data['parent_domain_id'] ?? 0), $accountId]);
            if ($parent === null) {
                app('session')->flash('error', 'Select a parent domain for the subdomain.');
                app('session')->flashInput($request->all());
                return Response::redirect($this->createPathFor($type));
            }
            $sub = strtolower(trim((string) ($data['subdomain'] ?? '')));
            $name = $sub . '.' . $parent['name'];
        } else {
            $name = strtolower(trim((string) ($data['name'] ?? '')));
        }

        if ($type === 'parked') {
            $redirectUrl = (string) ($data['redirect_url'] ?? '');
        }

        try {
            $this->assertCanStore($type, $name, $redirectUrl);
            $id = $this->insertDomain($accountId, $type, $name, $redirectUrl !== '' ? $redirectUrl : null, $parent);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            app('session')->flashInput($request->all());
            return Response::redirect($this->createPathFor($type));
        }

        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [$id]);

        // Provision the folder + DNS + vhost for the new domain.
        try {
            app(\Modules\Domains\Services\DomainService::class)->provision($account, $domain);
            app('session')->flash('success', 'Domain added.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Domain added, but provisioning failed: ' . $e->getMessage());
        }

        return Response::redirect('/panel/domains');
    }

    public function show(int $id): Response
    {
        return $this->showDomain($id, function (int $id): ?array {
            $accountId = $this->accountId();
            return app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]) ?: null;
        });
    }

    /**
     * Update the domain's mail/DNS profile (mail hosted here or elsewhere,
     * SPF mechanisms, DMARC policy/reports, webmail record, TTL). The profile
     * is applied immediately: records the new selection does not publish are
     * removed and the rest re-provisioned.
     */
    public function updateMailDns(int $id, Request $request): Response
    {
        $accountId = $this->accountId();
        $domain = app('db')->selectOne(
            'SELECT * FROM `domains` WHERE `id` = ? AND `account_id` = ?',
            [$id, $accountId]
        );
        if ($domain === null) {
            return Response::redirect('/panel/domains');
        }

        try {
            $profile = \Modules\Domains\Services\DomainService::validateMailDnsInput($request->all());
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/domains/' . $id);
        }

        app('db')->update('domains', $profile + ['updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [$id]);
        audit('domain.mail_dns', 'domain', $id, $profile);

        $fresh = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [$id]);
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        try {
            if ($fresh !== null && $account !== null) {
                app(\Modules\Domains\Services\DomainService::class)->applyMailDnsProfile($account, $fresh);
            }
            app('session')->flash('success', 'Mail & DNS settings saved.');
        } catch (\Throwable $e) {
            app('logger')->warning('Mail/DNS profile apply failed for ' . $domain['name'] . ': ' . $e->getMessage());
            app('session')->flash('error', 'Settings saved, but applying them to DNS failed: ' . $e->getMessage());
        }

        return Response::redirect('/panel/domains/' . $id);
    }

    public function destroy(int $id): Response
    {
        return $this->destroyDomain($id, function (int $id): ?array {
            $accountId = $this->accountId();
            return app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]) ?: null;
        });
    }
}
