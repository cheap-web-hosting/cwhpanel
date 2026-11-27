<?php

declare(strict_types=1);

namespace Modules\Dns;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'dns';
    }

    public function name(): string
    {
        return 'DNS';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function admin(): bool
    {
        return true;
    }

    public function customer(): bool
    {
        return true;
    }

    public function requires(): array
    {
        return ['core', 'accounts'];
    }

    public function permissions(): array
    {
        return [
            'dns.zones.view',
            'dns.zones.create',
            'dns.zones.delete',
            'dns.records.create',
            'dns.records.edit',
            'dns.records.delete',
        ];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:dns.zones.view']], function (Router $router) {
            $router->get('/dns', 'Modules\Dns\Controllers\AdminDnsController@index');
            $router->get('/dns/zones', 'Modules\Dns\Controllers\AdminDnsController@zones');
            $router->get('/dns/zones/create', 'Modules\Dns\Controllers\AdminDnsController@create');
            $router->post('/dns/nameservers', 'Modules\Dns\Controllers\AdminDnsController@updateNameservers');
            $router->post('/dns/mail-settings', 'Modules\Dns\Controllers\AdminDnsController@updateMailSettings');
            $router->post('/dns/zones', 'Modules\Dns\Controllers\AdminDnsController@store');
            $router->get('/dns/zones/{zoneName}', 'Modules\Dns\Controllers\AdminDnsController@show');
            $router->post('/dns/zones/{zoneName}/delete', 'Modules\Dns\Controllers\AdminDnsController@destroy');
            $router->post('/dns/zones/{zoneName}/records', 'Modules\Dns\Controllers\AdminDnsController@addRecord');
            $router->post('/dns/zones/{zoneName}/records/{recordId}/delete', 'Modules\Dns\Controllers\AdminDnsController@deleteRecord');
            $router->post('/dns/zones/{zoneName}/secure', 'Modules\Dns\Controllers\AdminDnsController@secure');
            $router->post('/dns/zones/{zoneName}/unsecure', 'Modules\Dns\Controllers\AdminDnsController@unsecure');
            $router->post('/dns/zones/{zoneName}/apply-template', 'Modules\Dns\Controllers\AdminDnsController@applyTemplate');
            $router->get('/dns/templates', 'Modules\Dns\Controllers\AdminDnsController@templates');
            $router->post('/dns/templates', 'Modules\Dns\Controllers\AdminDnsController@storeTemplate');
            $router->post('/dns/templates/{id}/delete', 'Modules\Dns\Controllers\AdminDnsController@deleteTemplate');
        });

        $router->group(['prefix' => '/panel', 'middleware' => ['auth.customer', 'feature.dns']], function (Router $router) {
            $router->get('/dns', 'Modules\Dns\Controllers\CustomerDnsController@index');
            $router->get('/dns/zones/{zoneName}', 'Modules\Dns\Controllers\CustomerDnsController@show');
            $router->post('/dns/zones/{zoneName}/records', 'Modules\Dns\Controllers\CustomerDnsController@addRecord');
            $router->post('/dns/zones/{zoneName}/records/{recordId}/delete', 'Modules\Dns\Controllers\CustomerDnsController@deleteRecord');
            $router->post('/dns/zones/{zoneName}/apply-template', 'Modules\Dns\Controllers\CustomerDnsController@applyTemplate');
        });
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'dns',
                    'label' => 'DNS',
                    'icon' => 'network',
                    'order' => 45,
                    'children' => [
                        ['id' => 'zones', 'label' => 'Zones', 'url' => '/admin/dns/zones', 'order' => 0],
                        ['id' => 'templates', 'label' => 'Templates', 'url' => '/admin/dns/templates', 'order' => 20],
                    ],
                ],
            ],
            'customer' => [
                [
                    'id' => 'dns',
                    'label' => 'DNS',
                    'icon' => 'network',
                    'order' => 30,
                    'children' => [
                        ['id' => 'zones', 'label' => 'DNS Zones', 'url' => '/panel/dns', 'order' => 0],
                    ],
                ],
            ],
        ];
    }

    public function migrations(): array
    {
        return [
            \Modules\Dns\Migrations\CreateDnsZonesTable::class,
            \Modules\Dns\Migrations\CreateDnsTemplatesTable::class,
            \Modules\Dns\Migrations\AddZoneSecondNameserver::class,
        ];
    }

    /**
     * Seed default system DNS templates (idempotent).
     */
    public function boot(): void
    {
        // Skip on pre-migration databases (clean installs): seeding happens
        // on the first post-migrate boot.
        if (!$this->tablesExist(['dns_templates'])) {
            return;
        }
        $db = app('db');

        $defaults = [
            [
                'name' => 'Web Hosting',
                'description' => 'Apex A record, www, mail, MX, SPF and DMARC.',
                'records' => implode("\n", [
                    'A|@|{IP}|3600',
                    'AAAA|@|{IPV6}|3600',
                    'CNAME|www|@|3600',
                    'A|mail|{IP}|3600',
                    'AAAA|mail|{IPV6}|3600',
                    'MX|@|10 mail.{DOMAIN}|3600',
                    'TXT|@|v=spf1 mx a -all|3600',
                    'TXT|_dmarc|v=DMARC1; p=none; rua=mailto:postmaster@{DOMAIN}|3600',
                ]),
            ],
            [
                'name' => 'Email Only',
                'description' => 'MX, SPF and DMARC for a mail-only domain.',
                'records' => implode("\n", [
                    'MX|@|10 mail.{DOMAIN}|3600',
                    'TXT|@|v=spf1 mx -all|3600',
                    'TXT|_dmarc|v=DMARC1; p=none; rua=mailto:postmaster@{DOMAIN}|3600',
                ]),
            ],
            [
                'name' => 'Subdomain Host',
                'description' => 'Point a subdomain at a single A record.',
                'records' => implode("\n", [
                    'A|@|{IP}|3600',
                ]),
            ],
        ];

        foreach ($defaults as $tpl) {
            $existing = $db->selectOne('SELECT `id` FROM `dns_templates` WHERE `name` = ?', [$tpl['name']]);
            if (!$existing) {
                $db->insert('dns_templates', [
                    'name' => $tpl['name'],
                    'description' => $tpl['description'],
                    'records' => $tpl['records'],
                    'is_system' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}