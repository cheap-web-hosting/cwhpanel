<?php

declare(strict_types=1);

namespace Modules\Admin;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'admin';
    }

    public function name(): string
    {
        return 'Admin';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function requires(): array
    {
        return ['core', 'authentication', 'authorization'];
    }

    public function permissions(): array
    {
        return [
            'modules.view',
            'modules.manage',
        ];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:modules.view']], function (Router $router) {
            $router->get('/settings/modules', 'Modules\Admin\Controllers\ModuleController@index');
            $router->post('/settings/modules/{id}/toggle', 'Modules\Admin\Controllers\ModuleController@toggle');
        });
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'settings',
                    'label' => 'Settings',
                    'icon' => 'settings',
                    'order' => 1000,
                    'children' => [
                        ['id' => 'modules', 'label' => 'Modules', 'url' => '/admin/settings/modules', 'order' => 100],
                    ],
                ],
            ],
        ];
    }
}
