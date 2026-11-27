<?php

declare(strict_types=1);

namespace Modules\Ssl\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Ssl\Services\SslService;

class AdminSslController
{
    private SslService $ssl;

    public function __construct(SslService $ssl)
    {
        $this->ssl = $ssl;
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND c.`domain_name` LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $certs = app('db')->select(
            "SELECT c.*, a.`username`, d.`name` AS domain_name
             FROM `certificates` c
             LEFT JOIN `accounts` a ON a.`id` = c.`account_id`
             LEFT JOIN `domains` d ON d.`id` = c.`domain_id`
             WHERE {$where} ORDER BY c.`created_at` DESC",
            $params
        );

        $content = view('admin/ssl/index', [
            'title' => 'SSL Certificates',
            'certs' => $certs,
            'search' => $search,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);
        $domains = app('db')->select('SELECT * FROM `domains` WHERE `status` = ? ORDER BY `name`', ['active']);

        $content = view('admin/ssl/form', [
            'title' => 'Issue Certificate',
            'accounts' => $accounts,
            'domains' => $domains,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function store(Request $request): Response
    {
        try {
            $data = $request->validate([
                'domain_name' => 'required|max:255',
                'account_id' => 'required|integer',
                'domain_id' => 'required|integer',
                'days' => 'integer',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/ssl/create');
        }
        \Modules\Packages\Services\LimitService::requireHardCap((int) $data['account_id'], 'certificates');

        $domainName = strtolower($data['domain_name']);
        $days = (int) ($data['days'] ?? 90);
        $type = (string) $request->input('type', 'self-signed');
        $accountId = (int) $data['account_id'];
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
        if ($account === null) { app('session')->flash('error', 'Selected account does not exist.'); return Response::redirect('/admin/ssl/create'); }
        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ? AND `account_id` = ? AND `status` = ?', [(int) $data['domain_id'], $accountId, 'active']);
        if ($domain === null || strtolower($domain['name']) !== $domainName) {
            app('session')->flash('error', 'Selected website must belong to the chosen account and must match the certificate domain.');
            return Response::redirect('/admin/ssl/create');
        }

        try {
            if ($type === 'acme') {
                $webRoot = trim((string) $request->input('web_root', '')) ?: ('/home/' . $account['username'] . '/public_html');
                $this->ssl->issueAcme($domainName, $account['username'], $webRoot);
                $cert = $this->ssl->readCert($domainName);
                $certType = 'acme';
            } else {
                if (!$this->ssl->issueSelfSigned($domainName, $days)) {
                    throw new \RuntimeException('self-signed issuance failed');
                }
                $cert = $this->ssl->readCert($domainName);
                $certType = 'self-signed';
            }
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Certificate issuance failed: ' . $e->getMessage());
            return Response::redirect('/admin/ssl/create');
        }

        $id = app('db')->insert('certificates', [
            'account_id' => $accountId,
            'domain_id' => (int) $data['domain_id'],
            'domain_name' => $domainName,
            'type' => $certType,
            'cert_path' => "/etc/cwhpanel/ssl/{$domainName}/cert.pem",
            'key_path' => "/etc/cwhpanel/ssl/{$domainName}/key.pem",
            'issued_at' => $cert['valid_from'] ?? date('Y-m-d H:i:s'),
            'expires_at' => $cert['valid_to'] ?? date('Y-m-d H:i:s', strtotime("+{$days} days")),
            'auto_renew' => $request->input('auto_renew') ? 1 : 0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        audit('ssl.issued', 'certificate', $id, ['domain' => $domainName]);
        app('session')->flash('success', 'Certificate issued.');

        return Response::redirect('/admin/ssl');
    }

    public function show(int $id): Response
    {
        $cert = app('db')->selectOne(
            "SELECT c.*, a.`username`, d.`name` AS domain_name
             FROM `certificates` c
             LEFT JOIN `accounts` a ON a.`id` = c.`account_id`
             LEFT JOIN `domains` d ON d.`id` = c.`domain_id`
             WHERE c.`id` = ?",
            [$id]
        );

        if ($cert === null) {
            return Response::redirect('/admin/ssl');
        }

        $content = view('admin/ssl/show', [
            'title' => 'Certificate: ' . ($cert['domain_name'] ?? ''),
            'cert' => $cert,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function toggleAutoRenew(int $id): Response
    {
        $cert = app('db')->selectOne('SELECT * FROM `certificates` WHERE `id` = ?', [$id]);

        if ($cert === null || $cert['type'] !== 'acme') {
            return Response::redirect('/admin/ssl');
        }

        app('db')->update('certificates', [
            'auto_renew' => $cert['auto_renew'] ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);

        audit('ssl.auto_renew_toggled', 'certificate', $id, ['enabled' => !$cert['auto_renew']]);
        app('session')->flash('success', 'Auto-renew ' . ($cert['auto_renew'] ? 'disabled' : 'enabled') . '.');

        return Response::redirect('/admin/ssl/' . $id);
    }

    public function renew(int $id): Response
    {
        $cert = app('db')->selectOne('SELECT * FROM `certificates` WHERE `id` = ?', [$id]);

        if ($cert === null) {
            return Response::redirect('/admin/ssl');
        }

        try {
            if ($cert['type'] === 'acme') {
                $this->ssl->renewAcme($cert['domain_name']);
                $new = $this->ssl->readCert($cert['domain_name']);
                $issuedAt = $new['valid_from'] ?? date('Y-m-d H:i:s');
                $expiresAt = $new['valid_to'] ?? $cert['expires_at'];
            } else {
                $this->ssl->remove($cert['domain_name']);
                if (!$this->ssl->issueSelfSigned($cert['domain_name'], 90)) {
                    throw new \RuntimeException('self-signed issuance failed');
                }
                $new = $this->ssl->readCert($cert['domain_name']);
                $issuedAt = $new['valid_from'] ?? date('Y-m-d H:i:s');
                $expiresAt = $new['valid_to'] ?? date('Y-m-d H:i:s', strtotime('+90 days'));
            }

            app('db')->update('certificates', [
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$id]);
            audit('ssl.renewed', 'certificate', $id);
            app('session')->flash('success', 'Certificate renewed.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Certificate renewal failed: ' . $e->getMessage());
        }

        return Response::redirect('/admin/ssl/' . $id);
    }

    public function destroy(int $id): Response
    {
        $cert = app('db')->selectOne('SELECT * FROM `certificates` WHERE `id` = ?', [$id]);

        if ($cert === null) {
            return Response::redirect('/admin/ssl');
        }

        $this->ssl->remove($cert['domain_name']);
        app('db')->delete('certificates', '`id` = ?', [$id]);

        audit('ssl.deleted', 'certificate', $id, ['domain' => $cert['domain_name']]);
        app('session')->flash('success', 'Certificate deleted.');

        return Response::redirect('/admin/ssl');
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