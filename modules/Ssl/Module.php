<?php

declare(strict_types=1);

namespace Modules\Ssl;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'ssl';
    }

    public function name(): string
    {
        return 'SSL';
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
        return ['core', 'accounts', 'domains'];
    }

    public function permissions(): array
    {
        return ['ssl.view', 'ssl.issue', 'ssl.renew', 'ssl.delete'];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:ssl.view']], function (Router $router) {
            $router->get('/ssl', 'Modules\Ssl\Controllers\AdminSslController@index');
            $router->get('/ssl/create', 'Modules\Ssl\Controllers\AdminSslController@create');
            $router->post('/ssl', 'Modules\Ssl\Controllers\AdminSslController@store');
            $router->get('/ssl/{id}', 'Modules\Ssl\Controllers\AdminSslController@show');
            $router->post('/ssl/{id}/renew', 'Modules\Ssl\Controllers\AdminSslController@renew');
            $router->post('/ssl/{id}/auto-renew', 'Modules\Ssl\Controllers\AdminSslController@toggleAutoRenew');
            $router->post('/ssl/{id}/delete', 'Modules\Ssl\Controllers\AdminSslController@destroy');
        });

        $router->group(['prefix' => '/panel', 'middleware' => ['auth.customer', 'feature.ssl']], function (Router $router) {
            $router->get('/ssl', 'Modules\Ssl\Controllers\CustomerSslController@index');
            $router->get('/ssl/create', 'Modules\Ssl\Controllers\CustomerSslController@create');
            $router->post('/ssl', 'Modules\Ssl\Controllers\CustomerSslController@store');
            $router->post('/ssl/custom', 'Modules\Ssl\Controllers\CustomerSslController@storeCustom');
            $router->post('/ssl/self-signed', 'Modules\Ssl\Controllers\CustomerSslController@storeSelfSigned');
            $router->get('/ssl/{id}', 'Modules\Ssl\Controllers\CustomerSslController@show');
            $router->post('/ssl/{id}/delete', 'Modules\Ssl\Controllers\CustomerSslController@destroy');
        });
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'ssl',
                    'label' => 'SSL',
                    'icon' => 'lock',
                    'order' => 50,
                    'children' => [
                        ['id' => 'certificates', 'label' => 'Certificates', 'url' => '/admin/ssl', 'order' => 0],
                        ['id' => 'issue', 'label' => 'Issue Certificate', 'url' => '/admin/ssl/create', 'order' => 10],
                    ],
                ],
            ],
            'customer' => [
                [
                    'id' => 'ssl',
                    'label' => 'SSL',
                    'icon' => 'lock',
                    'order' => 35,
                    'children' => [
                        ['id' => 'certificates', 'label' => 'SSL Certificates', 'url' => '/panel/ssl', 'order' => 0],
                        ['id' => 'issue', 'label' => 'Issue Certificate', 'url' => '/panel/ssl/create', 'order' => 10],
                    ],
                ],
            ],
        ];
    }

    public function migrations(): array
    {
        return [
            \Modules\Ssl\Migrations\CreateCertificatesTable::class,
            \Modules\Ssl\Migrations\AddRetryAfterToCertificates::class,
        ];
    }
}