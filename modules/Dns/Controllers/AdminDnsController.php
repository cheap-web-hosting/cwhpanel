<?php

declare(strict_types=1);

namespace Modules\Dns\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Dns\Services\DnsService;

class AdminDnsController
{
    private DnsService $dns;

    public function __construct(DnsService $dns)
    {
        $this->dns = $dns;
    }

    public function index(): Response
    {
        return Response::redirect('/admin/dns/zones');
    }

    public function zones(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND z.`name` LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $zones = app('db')->select(
            "SELECT z.*, a.`username` FROM `dns_zones` z
             LEFT JOIN `accounts` a ON a.`id` = z.`account_id`
             WHERE {$where} ORDER BY z.`name`",
            $params
        );

        [$ns1, $ns2] = \Modules\Dns\Services\DnsSettings::get();

        $content = view('admin/dns/zones', [
            'title' => 'DNS Zones',
            'zones' => $zones,
            'search' => $search,
            'nameservers' => ['ns1' => $ns1, 'ns2' => $ns2],
            'mailSettings' => [
                'prefix' => \Modules\Dns\Services\DnsSettings::mailHostnamePrefix(),
                'dkim' => \Modules\Dns\Services\DnsSettings::dkimSelector(),
            ],
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);

        $content = view('admin/dns/zone-create', [
            'title' => 'Add Zone',
            'accounts' => $accounts,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    /** GET data only: the form lives on the DNS overview page. */
    public function updateNameservers(Request $request): Response
    {
        $ns1 = \Modules\Domains\Services\DomainService::normalizeNameserver((string) $request->input('nameserver', ''));
        $ns2 = \Modules\Domains\Services\DomainService::normalizeNameserver((string) $request->input('nameserver2', ''));

        if (trim((string) $request->input('nameserver', '')) !== '' && $ns1 === null) {
            app('session')->flash('error', 'Nameserver 1 must be a hostname like ns1.example.com (not an IP address).');
            return Response::redirect('/admin/dns/zones');
        }
        if (trim((string) $request->input('nameserver2', '')) !== '' && $ns2 === null) {
            app('session')->flash('error', 'Nameserver 2 must be a hostname like ns2.example.com (not an IP address).');
            return Response::redirect('/admin/dns/zones');
        }

        if ($ns1 === null) {
            app('session')->flash('error', 'At least the primary nameserver is required — without it the panel cannot host DNS.');
            return Response::redirect('/admin/dns/zones');
        }

        if ($ns2 === $ns1) {
            $ns2 = null;
        }

        \Modules\Dns\Services\DnsSettings::set($ns1, $ns2);
        $updated = \Modules\Dns\Services\DnsSettings::applyToAllZones($ns1, $ns2);
        audit('dns.nameservers.updated', 'settings', 0, ['ns1' => $ns1, 'ns2' => $ns2, 'zones' => $updated]);

        app('session')->flash('success', 'Server nameservers saved and applied to ' . $updated . ' zone' . ($updated === 1 ? '' : 's') . '.');
        return Response::redirect('/admin/dns/zones');
    }

    /** Update the server-wide mail hostname prefix + DKIM selector. */
    public function updateMailSettings(Request $request): Response
    {
        try {
            [$prefix, $selector] = \Modules\Dns\Services\DnsSettings::validateMailSettings(
                (string) $request->input('mail_hostname_prefix', 'mail.'),
                (string) $request->input('dkim_selector', 'default')
            );
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/dns/zones');
        }

        \Modules\Dns\Services\DnsSettings::setMailSettings($prefix, $selector);
        audit('dns.mail_settings.updated', 'settings', 0, ['prefix' => $prefix, 'dkim' => $selector]);
        app('session')->flash('success', 'Mail hostname and DKIM selector saved (applies to zones provisioned from now on).');

        return Response::redirect('/admin/dns/zones');
    }

    public function store(Request $request): Response
    {
        try {
            $data = $request->validate([
                'name' => 'required|max:255',
                'account_id' => 'required|integer',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/dns/zones/create');
        }

        $zone = strtolower(trim((string) $data['name']));

        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $zone)) {
            app('session')->flash('error', 'Please enter a valid zone name.');
            app('session')->flashInput($request->all());
            return Response::redirect('/admin/dns/zones/create');
        }

        // Nameservers are a server setting (Admin → DNS → Nameservers); every
        // zone is published with the same pair.
        [$nameserver, $nameserver2] = \Modules\Dns\Services\DnsSettings::get();
        if ($nameserver === null) {
            app('session')->flash('error', 'Set the server nameservers first (DNS → Nameservers) — a zone cannot be published without them.');
            app('session')->flashInput($request->all());
            return Response::redirect('/admin/dns/zones/create');
        }

        if (app('db')->selectOne('SELECT `id` FROM `dns_zones` WHERE `name` = ?', [$zone]) !== null) {
            app('session')->flash('error', 'Zone already exists.');
            app('session')->flashInput($request->all());
            return Response::redirect('/admin/dns/zones/create');
        }

        $account = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `id` = ?', [(int) $data['account_id']]);
        if ($account === null) {
            app('session')->flash('error', 'Selected account does not exist.');
            app('session')->flashInput($request->all());
            return Response::redirect('/admin/dns/zones/create');
        }

        try {
            $this->dns->createZone($zone, $nameserver, $nameserver2);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Zone provisioning failed: ' . $e->getMessage());
            app('session')->flashInput($request->all());
            return Response::redirect('/admin/dns/zones/create');
        }

        app('db')->insert('dns_zones', [
            'name' => $zone,
            'account_id' => (int) $data['account_id'],
            'nameserver' => $nameserver,
            'nameserver2' => $nameserver2,
            'dnssec' => 0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        audit('dns.zone.created', 'dns_zone', $zone);
        app('session')->flash('success', 'Zone created.');

        return Response::redirect('/admin/dns/zones/' . $zone);
    }

    public function show(string $zoneName): Response
    {
        $zone = app('db')->selectOne(
            'SELECT z.*, a.`username` FROM `dns_zones` z
             LEFT JOIN `accounts` a ON a.`id` = z.`account_id`
             WHERE z.`name` = ?',
            [$zoneName]
        );

        if ($zone === null) {
            return Response::redirect('/admin/dns/zones');
        }

        $output = '';
        $records = [];

        if ($zone['status'] === 'active') {
            try {
                $output = $this->dns->listZone($zoneName);
                $records = $this->dns->parseZoneOutput($output);
            } catch (\Throwable $e) {
                $output = 'Error: ' . $e->getMessage();
            }
        }

        $content = view('admin/dns/zone-show', [
            'title' => 'Zone: ' . $zoneName,
            'zone' => $zone,
            'records' => $records,
            'rawOutput' => $output,
            'dsRecords' => !empty($zone['dnssec']) ? \Modules\Dns\Services\DnsService::parseDs($this->dns->showZone($zoneName)) : [],
            'templates' => app('db')->select('SELECT * FROM `dns_templates` ORDER BY `name`'),
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function destroy(string $zoneName): Response
    {
        $zone = app('db')->selectOne('SELECT * FROM `dns_zones` WHERE `name` = ?', [$zoneName]);

        if ($zone === null) {
            return Response::redirect('/admin/dns/zones');
        }

        $this->dns->deleteZone($zoneName);
        app('db')->delete('dns_zones', '`name` = ?', [$zoneName]);

        audit('dns.zone.deleted', 'dns_zone', $zoneName);
        app('session')->flash('success', 'Zone deleted.');

        return Response::redirect('/admin/dns/zones');
    }

    public function addRecord(string $zoneName, Request $request): Response
    {
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
            return Response::redirect('/admin/dns/zones/' . $zoneName);
        }

        $ok = $this->dns->upsertRecord(
            $zoneName,
            $data['name'],
            strtoupper($data['type']),
            $data['content'],
            (int) ($data['ttl'] ?? 3600)
        );

        audit('dns.record.upserted', 'dns_zone', $zoneName, ['type' => $data['type'], 'name' => $data['name']]);

        if ($ok) {
            app('session')->flash('success', 'Record added.');
        } else {
            app('session')->flash('error', 'Failed to add record.');
        }

        return Response::redirect('/admin/dns/zones/' . $zoneName);
    }

    public function deleteRecord(string $zoneName, string $recordId): void
    {
        $parts = explode(':', rawurldecode($recordId), 2);
        $type = $parts[0] ?? '';
        $name = $parts[1] ?? '';

        if ($type !== '' && $name !== '') {
            $this->dns->deleteRecord($zoneName, $name, $type);
            audit('dns.record.deleted', 'dns_zone', $zoneName, ['type' => $type, 'name' => $name]);
            app('session')->flash('success', 'Record deleted.');
        }

        Response::redirect('/admin/dns/zones/' . $zoneName)->send();
    }

    public function secure(string $zoneName): Response
    {
        $zone = app('db')->selectOne('SELECT * FROM `dns_zones` WHERE `name` = ?', [$zoneName]);

        if ($zone === null) {
            return Response::redirect('/admin/dns/zones');
        }

        $ok = $this->dns->secureZone($zoneName);

        if ($ok) {
            app('db')->update('dns_zones', ['dnssec' => 1, 'updated_at' => date('Y-m-d H:i:s')], '`name` = ?', [$zoneName]);
            audit('dns.dnssec.enabled', 'dns_zone', $zoneName);
            app('session')->flash('success', 'DNSSEC enabled.');
        } else {
            app('session')->flash('error', 'DNSSEC could not be enabled.');
        }

        return Response::redirect('/admin/dns/zones/' . $zoneName);
    }

    public function unsecure(string $zoneName): Response
    {
        $zone = app('db')->selectOne('SELECT * FROM `dns_zones` WHERE `name` = ?', [$zoneName]);

        if ($zone === null) {
            return Response::redirect('/admin/dns/zones');
        }

        $ok = $this->dns->unsecureZone($zoneName);

        if ($ok) {
            app('db')->update('dns_zones', ['dnssec' => 0, 'updated_at' => date('Y-m-d H:i:s')], '`name` = ?', [$zoneName]);
            audit('dns.dnssec.disabled', 'dns_zone', $zoneName);
            app('session')->flash('success', 'DNSSEC disabled. Remember to remove the DS records at the registrar.');
        } else {
            app('session')->flash('error', 'DNSSEC could not be disabled.');
        }

        return Response::redirect('/admin/dns/zones/' . $zoneName);
    }

    public function templates(): Response
    {
        $templates = app('db')->select('SELECT * FROM `dns_templates` ORDER BY `is_system` DESC, `name`');

        $content = view('admin/dns/templates', [
            'title' => 'DNS Templates',
            'templates' => $templates,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function storeTemplate(Request $request): Response
    {
        try {
            $data = $request->validate([
                'name' => 'required|max:191',
                'records' => 'required',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/dns/templates');
        }

        app('db')->insert('dns_templates', [
            'name' => $data['name'],
            'description' => $request->input('description', ''),
            'records' => $data['records'],
            'is_system' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        audit('dns.template.created', 'dns_template', null, ['name' => $data['name']]);
        app('session')->flash('success', 'Template saved.');

        return Response::redirect('/admin/dns/templates');
    }

    public function deleteTemplate(int $id): Response
    {
        $template = app('db')->selectOne('SELECT * FROM `dns_templates` WHERE `id` = ?', [$id]);

        if ($template === null) {
            return Response::redirect('/admin/dns/templates');
        }

        app('db')->delete('dns_templates', '`id` = ?', [$id]);
        audit('dns.template.deleted', 'dns_template', $id);
        app('session')->flash('success', 'Template deleted.');

        return Response::redirect('/admin/dns/templates');
    }

    public function applyTemplate(string $zoneName, Request $request): Response
    {
        $zone = app('db')->selectOne('SELECT * FROM `dns_zones` WHERE `name` = ?', [$zoneName]);

        if ($zone === null) {
            return Response::redirect('/admin/dns/zones');
        }

        try {
            $data = $request->validate(['template_id' => 'required|integer']);
        } catch (ValidationException $e) {
            app('session')->flash('error', 'Please select a template.');
            return Response::redirect('/admin/dns/zones/' . $zoneName);
        }

        $template = app('db')->selectOne('SELECT * FROM `dns_templates` WHERE `id` = ?', [(int) $data['template_id']]);

        if ($template === null) {
            app('session')->flash('error', 'Template not found.');
            return Response::redirect('/admin/dns/zones/' . $zoneName);
        }

        $result = $this->dns->applyTemplate($template['records'], $zoneName, $this->templateVars((int) $zone['account_id']));

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

        return Response::redirect('/admin/dns/zones/' . $zoneName);
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

    /** Template vars for a zone's account (dedicated IPv6 for {IPV6}). */
    private function templateVars(int $accountId): array
    {
        try {
            if (!app(\Modules\Network\Services\IpAllocatorService::class)->enabled()) {
                return [];
            }
            $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$accountId]);
            $v6 = trim((string) ($account['ipv6_address'] ?? ''));
            return ($v6 !== '' && filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) ? ['IPV6' => $v6] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}