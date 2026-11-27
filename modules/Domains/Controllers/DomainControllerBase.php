<?php

declare(strict_types=1);

namespace Modules\Domains\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;

/**
 * Shared plumbing for the admin/customer domain controllers. The two panels
 * duplicate the same validation → insert → provision flow, differing only in
 * account scoping, form chrome and redirect prefixes. Everything identical
 * lives here so fixes land once (e.g. parked-redirect validation, duplicate
 * checks, count limits, audit events).
 */
abstract class DomainControllerBase
{
    /** '/admin' or '/panel' — every redirect target derives from this. */
    abstract protected function areaPrefix(): string;

    /** 'layouts/admin' or 'layouts/customer'. */
    abstract protected function layout(): string;

    /** 'admin/...' or 'customer/...' prefix for views. */
    abstract protected function viewArea(string $template): string;

    final protected function homePath(): string
    {
        return $this->areaPrefix() . '/domains';
    }

    final protected function createPathFor(string $type): string
    {
        $map = [
            'addon' => '/create/addon',
            'parked' => '/create/parked',
            'subdomain' => '/create/subdomain',
        ];
        return $this->homePath() . ($map[$type] ?? '');
    }

    final protected function backWithErrors(Request $request, ValidationException $e, string $path): Response
    {
        app('session')->flashInput($request->all());
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $msg) {
                app('session')->pushError($field, $msg);
            }
        }
        return Response::redirect($path);
    }

    /**
     * Shared create validation. Throws \RuntimeException with a user-facing
     * message on refusal; callers catch it and redirect back to the form.
     */
    final protected function assertCanStore(string $type, string $name, string $redirectUrl): void
    {
        if (!in_array($type, \Modules\Domains\Services\DomainService::TYPES, true)) {
            throw new \RuntimeException('Invalid domain type.');
        }
        if (!\Modules\Domains\Services\DomainService::validDomain($name)) {
            throw new \RuntimeException('Please enter a valid domain name.');
        }
        // Parked redirects must be public http(s), not open redirects or
        // internal hops.
        if ($type === 'parked' && $redirectUrl !== ''
            && !\Modules\Domains\Services\DomainService::validRedirectUrl($redirectUrl)) {
            throw new \RuntimeException('Redirect URL must be http(s) to a public address.');
        }
        if (app('db')->selectOne('SELECT `id` FROM `domains` WHERE `name` = ?', [$name]) !== null) {
            throw new \RuntimeException('Domain already exists.');
        }
    }

    /**
     * Shared insert core: count limit + row insert + event + audit.
     *
     * @throws \RuntimeException with a user-facing message when a package
     *         count limit refuses the domain.
     */
    final protected function insertDomain(
        int $accountId,
        string $type,
        string $name,
        ?string $redirectUrl,
        ?array $parent
    ): int {
        try {
            \Modules\Packages\Services\LimitService::require($accountId, $type === 'subdomain' ? 'subdomains' : 'domains');
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage());
        }

        $id = (int) app('db')->insert('domains', [
            'name' => $name,
            'account_id' => $accountId,
            'type' => $type,
            'parent_domain_id' => $type === 'subdomain' ? (int) $parent['id'] : null,
            'redirect_url' => ($type === 'parked' && $redirectUrl !== '') ? $redirectUrl : null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        event('DomainCreated', ['domain_id' => $id, 'name' => $name]);
        audit('domain.created', 'domain', $id, ['name' => $name, 'type' => $type]);

        return $id;
    }

    final protected function showDomain(int $id, callable $find): Response
    {
        $domain = $find($id);
        if ($domain === null) {
            return Response::redirect($this->homePath());
        }

        $content = view($this->viewArea('domains/show'), [
            'title' => 'Domain: ' . $domain['name'],
            'domain' => $domain,
        ], $this->layout());

        return new Response((string) $content);
    }

    final protected function destroyDomain(int $id, callable $find): Response
    {
        $domain = $find($id);
        if ($domain === null) {
            return Response::redirect($this->homePath());
        }

        $steps = app(\Modules\Domains\Services\DomainService::class)->deprovision($domain);
        app('db')->delete('domains', '`id` = ?', [$id]);
        event('DomainDeleted', ['domain_id' => $id, 'name' => $domain['name']]);
        audit('domain.deleted', 'domain', $id, ['name' => $domain['name']]);
        $failed = array_values(array_filter($steps, fn ($s) => !$s['ok']));
        if ($failed === []) {
            app('session')->flash('success', 'Domain deleted (website, mail and DNS cleaned up).');
        } else {
            app('session')->flash('error', 'Domain deleted, but cleanup was incomplete: ' .
                implode('; ', array_map(fn ($s) => $s['step'] . ' — ' . $s['detail'], $failed)));
        }

        return Response::redirect($this->homePath());
    }
}
