<?php

declare(strict_types=1);

namespace Modules\Authentication;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'authentication';
    }

    public function name(): string
    {
        return 'Authentication';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function requires(): array
    {
        return ['core'];
    }

    public function routes(Router $router): void
    {
        $router->group(['middleware' => ['guest']], function (Router $router) {
            $router->get('/login', 'Modules\Authentication\Controllers\LoginController@show');
            $router->post('/login', 'Modules\Authentication\Controllers\LoginController@login');
            $router->get('/panel/login', 'Modules\Authentication\Controllers\LoginController@showCustomer');
            $router->post('/panel/login', 'Modules\Authentication\Controllers\LoginController@loginCustomer');
        });

        // Two-factor challenge (reached after password auth when 2FA is enabled).
        $router->get('/login/2fa', 'Modules\Authentication\Controllers\LoginController@showTwoFactor');
        $router->post('/login/2fa', 'Modules\Authentication\Controllers\LoginController@verifyTwoFactor');
        $router->get('/panel/login/2fa', 'Modules\Authentication\Controllers\LoginController@showCustomerTwoFactor');
        $router->post('/panel/login/2fa', 'Modules\Authentication\Controllers\LoginController@verifyCustomerTwoFactor');

        $router->post('/logout', 'Modules\Authentication\Controllers\LoginController@logout');

        // End admin impersonation. Deliberately behind plain `auth` (not
        // auth.admin): during masquerade the session IS the customer, so an
        // admin-only gate would lock the exit. No-op when not masquerading.
        $router->group(['middleware' => ['auth']], function (Router $router) {
            $router->post('/impersonate/stop', 'Modules\Authentication\Controllers\LoginController@stopImpersonate');
            // Step-up credential confirmation for destructive admin actions
            // (middleware `stepup` redirects here). Also under plain `auth`:
            // the controller guards admin-only access itself, and the route
            // deliberately carries no `can:` permission (it is the proof).
            $router->get('/admin/confirm', 'Modules\Authentication\Controllers\LoginController@showConfirm');
            $router->post('/admin/confirm', 'Modules\Authentication\Controllers\LoginController@confirm');
        });
    }

    public function migrations(): array
    {
        return [
            \Modules\Authentication\Migrations\CreateUsersTable::class,
            \Modules\Authentication\Migrations\CreateLoginAttemptsTable::class,
            \Modules\Authentication\Migrations\CreatePasswordResetTokensTable::class,
            \Modules\Authentication\Migrations\AddTwoFactorToUsersTable::class,
            \Modules\Authentication\Migrations\AddTwoFactorLastStep::class,
        ];
    }
}
