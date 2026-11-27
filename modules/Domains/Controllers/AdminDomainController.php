<?php

declare(strict_types=1);

namespace Modules\Domains\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;

class AdminDomainController extends DomainControllerBase
{
    protected function areaPrefix(): string
    {
        return '/admin';
    }

    protected function layout(): string
    {
        return 'layouts/admin';
    }

    protected function viewArea(string $template): string
    {
        return 'admin/' . $template;
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND d.`name` LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $domains = app('db')->select(
            "SELECT d.*, a.`username` FROM `domains` d
             LEFT JOIN `accounts` a ON a.`id` = d.`account_id`
             WHERE {$where} ORDER BY d.`name`",
            $params
        );

        $content = view('admin/domains/index', [
            'title' => 'Domains',
            'domains' => $domains,
            'search' => $search,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);
        return $this->renderForm('main', $accounts, null, []);
    }

    public function createAddon(): Response
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);
        return $this->renderForm('addon', $accounts, null, []);
    }

    public function createParked(): Response
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);
        return $this->renderForm('parked', $accounts, null, []);
    }

    public function createSubdomain(): Response
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);
        $parentDomains = app('db')->select('SELECT `id`,`name`,`type`,`account_id` FROM `domains` ORDER BY `name`');
        $content = view('admin/domains/subdomain', [
            'title' => 'Add Subdomain',
            'domain' => null,
            'old' => [],
            'accounts' => $accounts,
            'parentDomains' => $parentDomains,
        ], 'layouts/admin');
        return new Response((string) $content);
    }

    private function renderForm(string $type, array $accounts, ?array $domain, array $old): Response
    {
        $titles = ['main' => 'Add Domain', 'addon' => 'Add Addon Domain', 'parked' => 'Add Parked Domain'];
        $content = view('admin/domains/form', [
            'title' => $titles[$type] ?? 'Add Domain',
            'domain' => $domain,
            'old' => array_merge(['type' => $type], $old),
            'accounts' => $accounts,
            'lockedType' => true,
        ], 'layouts/admin');
        return new Response((string) $content);
    }

    public function store(Request $request): Response
    {
        $type = strtolower((string) ($request->input('type', 'main')));

        if (!in_array($type, \Modules\Domains\Services\DomainService::TYPES, true)) {
            app('session')->flash('error', 'Invalid domain type.');
            app('session')->flashInput($request->all());
            return Response::redirect($this->createPathFor('main'));
        }

        try {
            $data = $request->validate([
                'name' => 'max:255',
                'account_id' => 'required|integer',
                'type' => 'required',
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
            $parent = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) ($data['parent_domain_id'] ?? 0)]);
            if ($parent === null) {
                app('session')->flash('error', 'Select a parent domain.');
                return Response::redirect('/admin/domains/create/subdomain');
            }
            $sub = strtolower(trim((string) ($data['subdomain'] ?? '')));
            $name = $sub . '.' . $parent['name'];
            $parentAccountId = (int) $parent['account_id'];
            $accountId = (int) $data['account_id'] ?: $parentAccountId;
        } else {
            $name = strtolower(trim((string) ($data['name'] ?? '')));
            $accountId = (int) $data['account_id'];
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

        // Provision the folder + DNS + vhost for the new domain.
        try {
            $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
            $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [$id]);
            if ($account !== null && $domain !== null) {
                app(\Modules\Domains\Services\DomainService::class)->provision($account, $domain);
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Domain provisioning failed: ' . $e->getMessage());
        }

        app('session')->flash('success', 'Domain added.');

        return Response::redirect('/admin/domains');
    }

    public function show(int $id): Response
    {
        return $this->showDomain($id, fn (int $id): ?array => $this->find($id));
    }

    public function edit(int $id): Response
    {
        $domain = $this->find($id);

        if ($domain === null) {
            return Response::redirect('/admin/domains');
        }

        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);

        $content = view('admin/domains/form', [
            'title' => 'Edit Domain',
            'domain' => $domain,
            'old' => [],
            'accounts' => $accounts,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function update(int $id, Request $request): Response
    {
        $domain = $this->find($id);

        if ($domain === null) {
            return Response::redirect('/admin/domains');
        }

        try {
            $data = $request->validate([
                'account_id' => 'required|integer',
                'type' => 'required|alpha_dash',
                'document_root' => 'max:255',
                'redirect_url' => 'max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/domains/' . $id . '/edit');
        }

        $type = strtolower((string) $data['type']);
        if (!in_array($type, \Modules\Domains\Services\DomainService::TYPES, true)) {
            app('session')->flash('error', 'Invalid domain type.');
            return Response::redirect('/admin/domains/' . $id . '/edit');
        }

        $documentRoot = trim((string) ($data['document_root'] ?? ''));
        if ($documentRoot !== '' && ($documentRoot[0] !== '/' || str_contains($documentRoot, '..'))) {
            app('session')->flash('error', 'Document root must be an absolute path without "..".');
            return Response::redirect('/admin/domains/' . $id . '/edit');
        }

        $redirectUrl = trim((string) ($data['redirect_url'] ?? ''));
        if (!\Modules\Domains\Services\DomainService::validRedirectUrl($redirectUrl)) {
            app('session')->flash('error', 'Redirect URL must be http(s) to a public address.');
            return Response::redirect('/admin/domains/' . $id . '/edit');
        }

        // Only touch the mail/DNS profile when the form actually carried it —
        // other callers (API/test flows) must not silently reset it.
        $input = $request->all();
        $profileKeys = ['mail_mode', 'spf_extra', 'dmarc_policy', 'dmarc_rua', 'webmail_record', 'dns_ttl'];
        $hasProfile = array_intersect_key($input, array_flip($profileKeys)) !== [];
        $profile = [];
        if ($hasProfile) {
            try {
                $profile = \Modules\Domains\Services\DomainService::validateMailDnsInput($input);
            } catch (\RuntimeException $e) {
                app('session')->flash('error', $e->getMessage());
                return Response::redirect('/admin/domains/' . $id . '/edit');
            }
        }

        app('db')->update('domains', $profile + [
            'account_id' => (int) $data['account_id'],
            'type' => $type,
            'document_root' => $documentRoot !== '' ? $documentRoot : null,
            'redirect_url' => $redirectUrl !== '' ? $redirectUrl : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);

        // Re-apply DNS so the mail/DNS profile takes effect immediately.
        if ($hasProfile) {
            try {
                $fresh = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [$id]);
                $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $data['account_id']]);
                if ($fresh !== null && $account !== null) {
                    app(\Modules\Domains\Services\DomainService::class)->applyMailDnsProfile($account, $fresh);
                }
            } catch (\Throwable $e) {
                app('logger')->warning('Mail/DNS profile apply failed for domain ' . $id . ': ' . $e->getMessage());
                app('session')->flash('error', 'Domain saved, but applying the mail/DNS profile failed: ' . $e->getMessage());
            }
        }


        event('DomainUpdated', ['domain_id' => $id]);
        audit('domain.updated', 'domain', $id);
        app('session')->flash('success', 'Domain updated.');

        return Response::redirect('/admin/domains');
    }

    public function destroy(int $id): Response
    {
        return $this->destroyDomain($id, fn (int $id): ?array => $this->find($id));
    }

    private function find(int $id): ?array
    {
        return app('db')->selectOne(
            'SELECT d.*, a.`username` FROM `domains` d LEFT JOIN `accounts` a ON a.`id` = d.`account_id` WHERE d.`id` = ?',
            [$id]
        ) ?: null;
    }
}
