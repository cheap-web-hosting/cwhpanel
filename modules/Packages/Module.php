<?php

declare(strict_types=1);

namespace Modules\Packages;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'packages';
    }

    public function name(): string
    {
        return 'Packages';
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
        return ['core'];
    }

    public function permissions(): array
    {
        return [
            'packages.view',
            'packages.create',
            'packages.edit',
            'packages.delete',
        ];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:packages.view']], function (Router $router) {
            $router->get('/packages', 'Modules\Packages\Controllers\PackageController@index');
            $router->get('/packages/create', 'Modules\Packages\Controllers\PackageController@create');
            $router->post('/packages', 'Modules\Packages\Controllers\PackageController@store');
            $router->get('/packages/{id}', 'Modules\Packages\Controllers\PackageController@show');
            $router->get('/packages/{id}/edit', 'Modules\Packages\Controllers\PackageController@edit');
            $router->post('/packages/{id}', 'Modules\Packages\Controllers\PackageController@update');
            $router->post('/packages/{id}/duplicate', 'Modules\Packages\Controllers\PackageController@duplicate');
            $router->post('/packages/{id}/delete', 'Modules\Packages\Controllers\PackageController@destroy');
        });
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'packages',
                    'label' => 'Packages',
                    'icon' => 'package',
                    'order' => 30,
                    'children' => [
                        ['id' => 'list', 'label' => 'Hosting Packages', 'url' => '/admin/packages', 'order' => 0],
                        ['id' => 'add', 'label' => 'Add Package', 'url' => '/admin/packages/create', 'order' => 10],
                    ],
                ],
            ],
        ];
    }

    public function migrations(): array
    {
        return [
            \Modules\Packages\Migrations\CreatePackagesTable::class,
            \Modules\Packages\Migrations\AddEmailLimits::class,
            \Modules\Packages\Migrations\AddNginxLimitsToPackages::class,
            \Modules\Packages\Migrations\AddIoLimitToPackages::class,
            \Modules\Packages\Migrations\AddRateLimitToPackages::class,
            \Modules\Packages\Migrations\AddMysqlGovernorToPackages::class,
            \Modules\Packages\Migrations\AddMailboxQuotaMaxToPackages::class,
            \Modules\Packages\Migrations\AddPhpVersionToPackages::class,
            \Modules\Packages\Migrations\AddBandwidthUsageTable::class,
            \Modules\Packages\Migrations\AddBandwidthCursorsTable::class,
            \Modules\Packages\Migrations\CreatePackageFeaturesTable::class,
            \Modules\Packages\Migrations\AddResourceLimitsToPackages::class,
            \Modules\Packages\Migrations\MakePhpLimitsSigned::class,
            \Modules\Packages\Migrations\MakeCountLimitsNullable::class,
        ];
    }
}
