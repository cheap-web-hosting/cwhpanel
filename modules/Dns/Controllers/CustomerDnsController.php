<?php

declare(strict_types=1);

namespace Modules\Dns\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Dns\Services\DnsService;

class CustomerDnsController
{
    private DnsService $dns;

    public function __construct(DnsService $dns)
    {
        $this->dns = $dns;
    }

    private function accountId(): ?int
    {
        $user = app('auth')->user();
        if ($user === null) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id` = ?', [$user->id]);
        return $row !== null ? (int) $row['id'] : null;
    }

    /** Load a DNS zone owned by the current account (IDOR-safe). */
    private function ownedZone(string $zoneName): ?array
    {
        $accountId = $this->accountId();
        if ($accountId === null) return null;
        return app('db')->selectOne('SELECT * FROM `dns_zones` WHERE `name` = ? AND `account_id` = ?', [$zoneName, $accountId]) ?: null;
    }

    public function index(): Response
    {
        $accountId = $this->accountId();
        if ($accountId === null) return Response::redirect('/panel');

        $zones = app('db')->select('SELECT * FROM `dns_zones` WHERE `account_id` = ? ORDER BY `name`', [$accountId]);

        $content = view('customer/dns/index', [
            'title' => 'DNS Zones',
            'zones' => $zones,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function show(string $zoneName): Response
    {
        $accountId = $this->accountId();
        $zone = app('db')->selectOne('SELECT * FROM `dns_zones` WHERE `name` = ? AND `account_id` = ?', [$zoneName, $accountId]);

        if ($zone === null) {
            return Response::redirect('/panel/dns');
        }

        $records = [];
        $error = null;

        try {
            $output = $this->dns->listZone($zoneName);
            $records = $this->dns->parseZoneOutput($output);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $templates = app('db')->select('SELECT * FROM `dns_templates` ORDER BY `name`');

        $content = view('customer/dns/show', [
            'title' => 'Zone: ' . $zoneName,
            'zone' => $zone,
            'records' => $records,
            'templates' => $templates,
            'error' => $error,
            'dsRecords' => !empty($zone['dnssec']) ? \Modules\Dns\Services\DnsService::parseDs($this->dns->showZone($zoneName)) : [],
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function applyTemplate(string $zoneName, Request $request): Response
    {
        $accountId = $this->accountId();
        $zone = app('db')->selectOne('SELECT * FROM `dns_zones` WHERE `name` = ? AND `account_id` = ?', [$zoneName, $accountId]);

        if ($zone === null) {
            return Response::redirect('/panel/dns');
        }

        try {
            $data = $request->validate(['template_id' => 'required|integer']);
        } catch (ValidationException $e) {
            app('session')->flash('error', 'Please select a template.');
            return Response::redirect('/panel/dns/zones/' . $zoneName);
        }

        $template = app('db')->selectOne('SELECT * FROM `dns_templates` WHERE `id` = ?', [(int) $data['template_id']]);

        if ($template === null) {
            app('session')->flash('error', 'Template not found.');
            return Response::redirect('/panel/dns/zones/' . $zoneName);
        }

        $result = $this->dns->applyTemplate($template['records'], $zoneName, $this->templateVars($accountId));

        if ($result['applied'] > 0) {
            app('session')->flash('success', sprintf(
                'Template "%s" applied: %d record(s) added.',
                $template['name'],
                $result['applied']
            ));
        } else {
            app('session')->flash('error', 'No records were applied.');
        }

        audit('dns.template.applied', 'dns_zone', $zoneName, ['template' => $template['name'], 'applied' => $result['applied']]);

        return Response::redirect('/panel/dns/zones/' . $zoneName);
    }

    public function addRecord(string $zoneName, Request $request): Response
    {
        if ($this->ownedZone($zoneName) === null) {
            return Response::redirect('/panel/dns');
        }

        try {
            $data = $request->validate([
                'name' => 'required|max:255',
                'type' => 'required|max:10',
                'content' => 'required|max:64000',
                'ttl' => 'integer',
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    app('session')->pushError($field, $msg);
                }
            }
            return Response::redirect('/panel/dns/zones/' . $zoneName);
        }

        try {
            $ok = $this->dns->upsertRecord(
                $zoneName,
                $data['name'],
                strtoupper($data['type']),
                $data['content'],
                (int) ($data['ttl'] ?? 3600)
            );
        } catch (\Throwable $e) {
            $ok = false;
        }

        audit('dns.record.upserted', 'dns_zone', $zoneName, ['type' => $data['type']]);

        if ($ok) {
            app('session')->flash('success', 'Record added.');
        } else {
            app('session')->flash('error', 'Failed to add record.');
        }

        return Response::redirect('/panel/dns/zones/' . $zoneName);
    }

    public function deleteRecord(string $zoneName, string $recordId): void
    {
        if ($this->ownedZone($zoneName) === null) {
            Response::redirect('/panel/dns')->send();
            return;
        }
        $parts = explode(':', rawurldecode($recordId), 2);
        $type = $parts[0] ?? '';
        $name = $parts[1] ?? '';

        if ($type !== '' && $name !== '') {
            try {
                $ok = $this->dns->deleteRecord($zoneName, $name, $type);
            } catch (\Throwable $e) {
                $ok = false;
            }
            audit('dns.record.deleted', 'dns_zone', $zoneName, ['type' => $type]);
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Record deleted.' : 'Failed to delete record.');
        }

        Response::redirect('/panel/dns/zones/' . $zoneName)->send();
    }

    /** Template vars for the customer's account (dedicated IPv6 for {IPV6}). */
    private function templateVars(?int $accountId): array
    {
        if ($accountId === null) {
            return [];
        }
        try {
            if (!app(\Modules\Network\Services\IpAllocatorService::class)->enabled()) {
                return [];
            }
            $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
            $v6 = (string) (app(\Modules\Network\Services\IpAllocatorService::class)->effectiveIpv6($account) ?? '');
            return ($v6 !== '' && filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) ? ['IPV6' => $v6] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}