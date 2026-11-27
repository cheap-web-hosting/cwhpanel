<?php

declare(strict_types=1);

namespace Modules\Accounts;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'accounts';
    }

    public function name(): string
    {
        return 'Accounts';
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
        return false;
    }

    public function requires(): array
    {
        return ['core', 'packages'];
    }

    public function permissions(): array
    {
        return [
            'accounts.view',
            'accounts.create',
            'accounts.edit',
            'accounts.suspend',
            'accounts.terminate',
        ];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:accounts.view']], function (Router $router) {
            $router->get('/accounts', 'Modules\Accounts\Controllers\AccountController@index');
            $router->get('/accounts/create', 'Modules\Accounts\Controllers\AccountController@create');
            $router->post('/accounts', 'Modules\Accounts\Controllers\AccountController@store');
            $router->get('/accounts/{id}', 'Modules\Accounts\Controllers\AccountController@show');
            $router->get('/accounts/{id}/edit', 'Modules\Accounts\Controllers\AccountController@edit');
            $router->post('/accounts/{id}', 'Modules\Accounts\Controllers\AccountController@update');
            $router->post('/accounts/{id}/suspend', 'Modules\Accounts\Controllers\AccountController@suspend')->withMiddleware('stepup');
            $router->post('/accounts/{id}/unsuspend', 'Modules\Accounts\Controllers\AccountController@unsuspend')->withMiddleware('stepup');
            $router->post('/accounts/{id}/terminate', 'Modules\Accounts\Controllers\AccountController@terminate')->withMiddleware('stepup');
            $router->post('/accounts/{id}/change-password', 'Modules\Accounts\Controllers\AccountController@changePassword')->withMiddleware('stepup');
            $router->post('/accounts/{id}/change-system-password', 'Modules\Accounts\Controllers\AccountController@changeSystemPassword')->withMiddleware('stepup');
            $router->post('/accounts/{id}/impersonate', 'Modules\Accounts\Controllers\AccountController@impersonate')->withMiddleware('stepup');
            $router->post('/accounts/{id}/backup-encryption', 'Modules\Accounts\Controllers\AccountController@backupEncryptionToggle')->withMiddleware('stepup');
            $router->post('/accounts/{id}/reprovision', 'Modules\Accounts\Controllers\AccountController@reprovision')->withMiddleware('stepup');
            $router->post('/accounts/{id}/toggle-nginx-worker', 'Modules\Accounts\Controllers\AccountController@toggleNginxWorker')->withMiddleware('stepup');
            $router->post('/accounts/{id}/ssh/keys', 'Modules\Accounts\Controllers\AccountController@sshAddKey')->withMiddleware('stepup');
            $router->post('/accounts/{id}/ssh/keys/{index}/delete', 'Modules\Accounts\Controllers\AccountController@sshRemoveKey')->withMiddleware('stepup');
            $router->post('/accounts/{id}/ssh/shell', 'Modules\Accounts\Controllers\AccountController@sshShell')->withMiddleware('stepup');
        });
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'accounts',
                    'label' => 'Accounts',
                    'icon' => 'users',
                    'order' => 25,
                    'children' => [
                        ['id' => 'all', 'label' => 'All Accounts', 'url' => '/admin/accounts', 'order' => 0],
                        ['id' => 'add', 'label' => 'Add Account', 'url' => '/admin/accounts/create', 'order' => 10],
                    ],
                ],
            ],
        ];
    }

    public function migrations(): array
    {
        return [
            \Modules\Accounts\Migrations\CreateAccountsTable::class,
            \Modules\Accounts\Migrations\AddMcsCategory::class,
            \Modules\Accounts\Migrations\AddNginxWorkerToggle::class,
            \Modules\Accounts\Migrations\AddBackupEncryptionToAccounts::class,
            \Modules\Accounts\Migrations\AddIpv6ToAccounts::class,
        ];
    }
}