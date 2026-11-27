<?php

declare(strict_types=1);

namespace Modules\Domains;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'domains';
    }

    public function name(): string
    {
        return 'Domains';
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
            'domains.view',
            'domains.create',
            'domains.edit',
            'domains.delete',
        ];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:domains.view']], function (Router $router) {
            $router->get('/domains', 'Modules\Domains\Controllers\AdminDomainController@index');
            $router->get('/domains/create', 'Modules\Domains\Controllers\AdminDomainController@create');
            $router->get('/domains/create/addon', 'Modules\Domains\Controllers\AdminDomainController@createAddon');
            $router->get('/domains/create/parked', 'Modules\Domains\Controllers\AdminDomainController@createParked');
            $router->get('/domains/create/subdomain', 'Modules\Domains\Controllers\AdminDomainController@createSubdomain');
            $router->post('/domains', 'Modules\Domains\Controllers\AdminDomainController@store');
            $router->get('/domains/{id}', 'Modules\Domains\Controllers\AdminDomainController@show');
            $router->get('/domains/{id}/edit', 'Modules\Domains\Controllers\AdminDomainController@edit');
            $router->post('/domains/{id}', 'Modules\Domains\Controllers\AdminDomainController@update');
            $router->post('/domains/{id}/delete', 'Modules\Domains\Controllers\AdminDomainController@destroy');
        });

        $router->group(['prefix' => '/panel', 'middleware' => ['auth.customer', 'feature.domains']], function (Router $router) {
            $router->get('/domains', 'Modules\Domains\Controllers\CustomerDomainController@index');
            $router->get('/domains/create', 'Modules\Domains\Controllers\CustomerDomainController@createRedirect');
            $router->get('/domains/create/addon', 'Modules\Domains\Controllers\CustomerDomainController@createAddon');
            $router->get('/domains/create/parked', 'Modules\Domains\Controllers\CustomerDomainController@createParked');
            $router->get('/domains/create/subdomain', 'Modules\Domains\Controllers\CustomerDomainController@createSubdomain');
            $router->post('/domains', 'Modules\Domains\Controllers\CustomerDomainController@store');
            $router->get('/domains/{id}', 'Modules\Domains\Controllers\CustomerDomainController@show');
            $router->post('/domains/{id}/mail-dns', 'Modules\Domains\Controllers\CustomerDomainController@updateMailDns');
            $router->post('/domains/{id}/delete', 'Modules\Domains\Controllers\CustomerDomainController@destroy');
        });
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'domains',
                    'label' => 'Domains',
                    'icon' => 'globe',
                    'order' => 35,
                    'children' => [
                        ['id' => 'all', 'label' => 'All Domains', 'url' => '/admin/domains', 'order' => 0],
                        ['id' => 'add', 'label' => 'Add Domain', 'url' => '/admin/domains/create', 'order' => 10],
                        ['id' => 'addon', 'label' => 'Add Addon Domain', 'url' => '/admin/domains/create/addon', 'order' => 20],
                        ['id' => 'parked', 'label' => 'Add Parked Domain', 'url' => '/admin/domains/create/parked', 'order' => 30],
                        ['id' => 'subdomain', 'label' => 'Add Subdomain', 'url' => '/admin/domains/create/subdomain', 'order' => 40],
                    ],
                ],
            ],
            'customer' => [
                [
                    'id' => 'domains',
                    'label' => 'Domains',
                    'icon' => 'globe',
                    'order' => 20,
                    'children' => [
                        ['id' => 'all', 'label' => 'My Domains', 'url' => '/panel/domains', 'order' => 0],
                        ['id' => 'addon', 'label' => 'Add Addon Domain', 'url' => '/panel/domains/create/addon', 'order' => 20],
                        ['id' => 'parked', 'label' => 'Add Parked Domain', 'url' => '/panel/domains/create/parked', 'order' => 30],
                        ['id' => 'subdomain', 'label' => 'Add Subdomain', 'url' => '/panel/domains/create/subdomain', 'order' => 40],
                    ],
                ],
            ],
        ];
    }

    public function migrations(): array
    {
        return [
            \Modules\Domains\Migrations\CreateDomainsTable::class,
            \Modules\Domains\Migrations\AddParentDomain::class,
            \Modules\Domains\Migrations\DropDomainNameservers::class,
            \Modules\Domains\Migrations\AddMailDnsProfile::class,
        ];
    }
}