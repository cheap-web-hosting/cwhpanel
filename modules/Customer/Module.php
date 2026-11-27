<?php

declare(strict_types=1);

namespace Modules\Customer;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'customer';
    }

    public function name(): string
    {
        return 'Customer';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function admin(): bool
    {
        return false;
    }

    public function customer(): bool
    {
        return true;
    }

    public function requires(): array
    {
        return ['core', 'authentication'];
    }

    public function permissions(): array
    {
        return [
            'account.profile.view',
            'account.profile.edit',
        ];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/panel', 'middleware' => ['auth.customer']], function (Router $router) {
            $router->get('/account', 'Modules\Customer\Controllers\AccountController@profile');
            $router->post('/account', 'Modules\Customer\Controllers\AccountController@updateProfile');
            $router->post('/account/password', 'Modules\Customer\Controllers\AccountController@changePassword');
            $router->post('/account/system-password', 'Modules\Customer\Controllers\AccountController@changeSystemPassword');
            $router->post('/account/sessions/revoke', 'Modules\Customer\Controllers\AccountController@revokeSession');
            $router->post('/account/sessions/revoke-others', 'Modules\Customer\Controllers\AccountController@revokeOtherSessions');
            $router->get('/account/2fa', 'Modules\Authentication\Controllers\TwoFactorController@setup');
            $router->post('/account/2fa/enable', 'Modules\Authentication\Controllers\TwoFactorController@enable');
            $router->post('/account/2fa/disable', 'Modules\Authentication\Controllers\TwoFactorController@disable');
        });
    }

    public function navigation(): array
    {
        return [
            'customer' => [
                [
                    'id' => 'account',
                    'label' => 'Account',
                    'icon' => 'user-circle',
                    'order' => 1000,
                    'children' => [
                        ['id' => 'profile', 'label' => 'Profile', 'url' => '/panel/account', 'order' => 0],
                    ],
                ],
            ],
        ];
    }
}
