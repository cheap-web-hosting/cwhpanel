<?php

declare(strict_types=1);

namespace Modules\Ssl\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Ssl\Services\SslService;

class CustomerSslController
{
    private SslService $ssl;

    public function __construct(SslService $ssl)
    {
        $this->ssl = $ssl;
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
        if ($accountId === null) return Response::redirect('/panel');
        \Modules\Packages\Services\LimitService::requireHardCap($accountId, 'certificates');

        $certs = app('db')->select(
            'SELECT c.*, d.`name` AS domain_name FROM `certificates` c
             LEFT JOIN `domains` d ON d.`id` = c.`domain_id`
             WHERE c.`account_id` = ? ORDER BY c.`created_at` DESC',
            [$accountId]
        );

        $content = view('customer/ssl/index', [
            'title' => 'SSL Certificates',
            'certs' => $certs,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        $accountId = $this->accountId();
        $domains = app('db')->select('SELECT * FROM `domains` WHERE `account_id` = ? AND `status` = ? ORDER BY `name`', [$accountId, 'active']);

        $content = view('customer/ssl/form', [
            'title' => 'Issue Certificate',
            'domains' => $domains,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    /** Load an active hosting domain owned by the current account. */
    private function ownedDomain(int $accountId, int $domainId): ?array
    {
        return app('db')->selectOne(
            'SELECT * FROM `domains` WHERE `id` = ? AND `account_id` = ? AND `status` = ?',
            [$domainId, $accountId, 'active']
        ) ?: null;
    }

    /** Write the certificates row shared by all three issuance paths. */
    private function recordCert(int $accountId, int $domainId, string $domainName, string $type, ?string $validFrom, ?string $validTo, int $autoRenew): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) app('db')->insert('certificates', [
            'account_id' => $accountId,
            'domain_id' => $domainId,
            'domain_name' => $domainName,
            'type' => $type,
            'cert_path' => "/etc/cwhpanel/ssl/{$domainName}/cert.pem",
            'key_path' => "/etc/cwhpanel/ssl/{$domainName}/key.pem",
            'issued_at' => $validFrom ?? $now,
            'expires_at' => $validTo ?? $now,
            'auto_renew' => $autoRenew,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Let's Encrypt issuance: the customer only picks a domain. The webroot
     * comes from the domain's document root and DNS is pre-checked, so no
     * rate-limit quota is burned on doomed challenges. Auto-renew defaults on.
     */
    public function store(Request $request): Response
    {
        $accountId = $this->accountId();
        if ($accountId === null) return Response::redirect('/panel');
        \Modules\Packages\Services\LimitService::requireHardCap($accountId, 'certificates');

        try {
            $data = $request->validate(['domain_id' => 'required|integer']);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/panel/ssl/create');
        }

        $domain = $this->ownedDomain($accountId, (int) $data['domain_id']);
        if ($domain === null) {
            app('session')->flash('error', 'Select one of your domains.');
            return Response::redirect('/panel/ssl/create');
        }
        $domainName = strtolower($domain['name']);
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);

        $check = $this->ssl->pointsHere($domainName, $account ?: []);
        if (!$check['points']) {
            app('session')->flash('error', "Cannot issue yet: {$domainName} does not point here ({$check['detail']}). Point its DNS at this server first.");
            return Response::redirect('/panel/ssl/create');
        }

        try {
            $webRoot = ($domain['document_root'] ?? '') ?: ('/home/' . $account['username'] . '/public_html');
            $this->ssl->issueAcme($domainName, $account['username'], $webRoot);
            $cert = $this->ssl->readCert($domainName);
            $id = $this->recordCert($accountId, (int) $domain['id'], $domainName, 'acme', $cert['valid_from'] ?? null, $cert['valid_to'] ?? null, 1);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Certificate issuance failed: ' . $e->getMessage());
            return Response::redirect('/panel/ssl/create');
        }

        audit('ssl.issued', 'certificate', $id, ['domain' => $domainName]);
        app('session')->flash('success', 'Let\'s Encrypt certificate issued with auto-renew on.');

        return Response::redirect('/panel/ssl');
    }

    /** Install a customer-supplied certificate + private key. */
    public function storeCustom(Request $request): Response
    {
        $accountId = $this->accountId();
        if ($accountId === null) return Response::redirect('/panel');
        \Modules\Packages\Services\LimitService::requireHardCap($accountId, 'certificates');

        try {
            $data = $request->validate([
                'domain_id' => 'required|integer',
                'certificate' => 'required|max:65535',
                'private_key' => 'required|max:65535',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/panel/ssl/create');
        }

        $domain = $this->ownedDomain($accountId, (int) $data['domain_id']);
        if ($domain === null) {
            app('session')->flash('error', 'Select one of your domains.');
            return Response::redirect('/panel/ssl/create');
        }
        $domainName = strtolower($domain['name']);

        try {
            $info = $this->ssl->installCustom($domainName, (string) $data['certificate'], (string) $data['private_key']);
            $id = $this->recordCert($accountId, (int) $domain['id'], $domainName, 'custom', $info['valid_from'], $info['valid_to'], 0);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Custom certificate rejected: ' . $e->getMessage());
            return Response::redirect('/panel/ssl/create');
        }

        audit('ssl.custom.installed', 'certificate', $id, ['domain' => $domainName]);
        app('session')->flash('success', 'Custom certificate installed.');

        return Response::redirect('/panel/ssl');
    }

    /** Self-signed certificate: domain + validity only. */
    public function storeSelfSigned(Request $request): Response
    {
        $accountId = $this->accountId();
        if ($accountId === null) return Response::redirect('/panel');
        \Modules\Packages\Services\LimitService::requireHardCap($accountId, 'certificates');

        try {
            $data = $request->validate(['domain_id' => 'required|integer', 'days' => 'integer']);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/panel/ssl/create');
        }

        $domain = $this->ownedDomain($accountId, (int) $data['domain_id']);
        if ($domain === null) {
            app('session')->flash('error', 'Select one of your domains.');
            return Response::redirect('/panel/ssl/create');
        }
        $domainName = strtolower($domain['name']);
        $days = min(3650, max(1, (int) ($data['days'] ?? 90)));

        try {
            if (!$this->ssl->issueSelfSigned($domainName, $days)) {
                throw new \RuntimeException('self-signed issuance failed');
            }
            $cert = $this->ssl->readCert($domainName);
            $id = $this->recordCert($accountId, (int) $domain['id'], $domainName, 'self-signed', $cert['valid_from'] ?? null, $cert['valid_to'] ?? null, 0);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Certificate issuance failed: ' . $e->getMessage());
            return Response::redirect('/panel/ssl/create');
        }

        audit('ssl.issued', 'certificate', $id, ['domain' => $domainName]);
        app('session')->flash('success', 'Self-signed certificate issued.');

        return Response::redirect('/panel/ssl');
    }

    public function show(int $id): Response
    {
        $accountId = $this->accountId();
        $cert = app('db')->selectOne(
            'SELECT c.*, d.`name` AS domain_name FROM `certificates` c
             LEFT JOIN `domains` d ON d.`id` = c.`domain_id`
             WHERE c.`id` = ? AND c.`account_id` = ?',
            [$id, $accountId]
        );

        if ($cert === null) return Response::redirect('/panel/ssl');

        $content = view('customer/ssl/show', [
            'title' => 'Certificate: ' . ($cert['domain_name'] ?? ''),
            'cert' => $cert,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function destroy(int $id): Response
    {
        $accountId = $this->accountId();
        $cert = app('db')->selectOne('SELECT * FROM `certificates` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);

        if ($cert === null) return Response::redirect('/panel/ssl');

        $this->ssl->remove($cert['domain_name']);
        app('db')->delete('certificates', '`id` = ?', [$id]);

        audit('ssl.deleted', 'certificate', $id);
        app('session')->flash('success', 'Certificate deleted.');

        return Response::redirect('/panel/ssl');
    }

    private function backWithErrors(Request $request, ValidationException $e, string $path): Response
    {
        app('session')->flashInput($request->all());
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $msg) {
                app('session')->pushError($field, $msg);
            }
        }
        return Response::redirect($path);
    }
}