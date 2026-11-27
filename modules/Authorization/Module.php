<?php

declare(strict_types=1);

namespace Modules\Authorization;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'authorization';
    }

    public function name(): string
    {
        return 'Authorization';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function requires(): array
    {
        return ['core', 'authentication'];
    }

    public function permissions(): array
    {
        return [
            'access.roles.view',
            'access.roles.edit',
            'access.permissions.view',
            'access.users.view',
            'access.users.manage',
        ];
    }

    public function routes(Router $router): void
    {
        // Split groups: each area is gated by its own permission so a role
        // granted only access.roles.view cannot create/edit users, and a
        // mutating POST additionally requires the matching mutating permission
        // (Gate::canMutating; access.roles.edit / access.users.manage).
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:access.roles.view']], function (Router $router) {
            $router->get('/access/roles', 'Modules\Authorization\Controllers\RoleController@index');
            $router->post('/access/roles', 'Modules\Authorization\Controllers\RoleController@store');
            $router->get('/access/roles/{id}', 'Modules\Authorization\Controllers\RoleController@show');
            $router->post('/access/roles/{id}', 'Modules\Authorization\Controllers\RoleController@update');
            $router->post('/access/roles/{id}/delete', 'Modules\Authorization\Controllers\RoleController@destroy');
        });

        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:access.permissions.view']], function (Router $router) {
            $router->get('/access/permissions', 'Modules\Authorization\Controllers\PermissionController@index');
        });

        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:access.users.view']], function (Router $router) {
            $router->get('/access/users', 'Modules\Authorization\Controllers\UserController@index');
            $router->get('/access/users/create', 'Modules\Authorization\Controllers\UserController@create');
            $router->post('/access/users', 'Modules\Authorization\Controllers\UserController@store');
            $router->get('/access/users/{id}', 'Modules\Authorization\Controllers\UserController@edit');
            $router->post('/access/users/{id}', 'Modules\Authorization\Controllers\UserController@update');
        });
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'access-control',
                    'label' => 'Access Control',
                    'icon' => 'shield-check',
                    'order' => 20,
                    'children' => [
                        ['id' => 'roles', 'label' => 'Roles', 'url' => '/admin/access/roles', 'order' => 0],
                        ['id' => 'permissions', 'label' => 'Permissions', 'url' => '/admin/access/permissions', 'order' => 10],
                        ['id' => 'users', 'label' => 'Admin Users', 'url' => '/admin/access/users', 'order' => 20],
                    ],
                ],
            ],
        ];
    }

    public function migrations(): array
    {
        return [
            \Modules\Authorization\Migrations\CreateRolesTable::class,
            \Modules\Authorization\Migrations\CreatePermissionsTable::class,
            \Modules\Authorization\Migrations\CreateRolePermissionsTable::class,
        ];
    }
}
