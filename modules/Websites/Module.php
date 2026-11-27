<?php

declare(strict_types=1);

namespace Modules\Websites;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'websites';
    }

    public function name(): string
    {
        return 'Websites';
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
        return [
            'websites.view',
            'websites.create',
            'websites.edit',
            'websites.delete',
        ];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin', 'can:websites.view']], function (Router $router) {
            $router->get('/websites', 'Modules\Websites\Controllers\AdminWebsiteController@index');
            $router->get('/websites/create', 'Modules\Websites\Controllers\AdminWebsiteController@create');
            $router->post('/websites', 'Modules\Websites\Controllers\AdminWebsiteController@store');
            $router->get('/websites/{id}', 'Modules\Websites\Controllers\AdminWebsiteController@show');
            $router->get('/websites/{id}/edit', 'Modules\Websites\Controllers\AdminWebsiteController@edit');
            $router->post('/websites/{id}', 'Modules\Websites\Controllers\AdminWebsiteController@update');
            $router->post('/websites/{id}/delete', 'Modules\Websites\Controllers\AdminWebsiteController@destroy');
            $router->get('/websites/{id}/nginx', 'Modules\Websites\Controllers\AdminWebsiteController@nginx');
            $router->post('/websites/{id}/privacy/dirs', 'Modules\Websites\Controllers\AdminWebsiteController@privacyAddDir');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/delete', 'Modules\Websites\Controllers\AdminWebsiteController@privacyDeleteDir');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/toggle', 'Modules\Websites\Controllers\AdminWebsiteController@privacyToggleDir');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/users', 'Modules\Websites\Controllers\AdminWebsiteController@privacyAddUser');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/users/{userId}/delete', 'Modules\Websites\Controllers\AdminWebsiteController@privacyDeleteUser');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/leech', 'Modules\Websites\Controllers\AdminWebsiteController@leechMax');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/users/{userId}/suspend', 'Modules\Websites\Controllers\AdminWebsiteController@leechSuspend');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/users/{userId}/unsuspend', 'Modules\Websites\Controllers\AdminWebsiteController@leechUnsuspend');
            $router->get('/websites/{id}/logs/download', 'Modules\Websites\Controllers\AdminWebsiteController@logsDownload');
            $router->post('/websites/{id}/worker/restart', 'Modules\Websites\Controllers\AdminWebsiteController@workerRestart');
            $router->post('/websites/{id}/nginx-custom', 'Modules\Websites\Controllers\AdminWebsiteController@customNginxSave');
            $router->post('/websites/{id}/maintenance', 'Modules\Websites\Controllers\AdminWebsiteController@maintenanceToggle');
            $router->post('/websites/{id}/https', 'Modules\Websites\Controllers\AdminWebsiteController@httpsSave');
            $router->post('/websites/{id}/ipv4', 'Modules\Websites\Controllers\AdminWebsiteController@ipv4Save');
            $router->post('/websites/{id}/bandwidth-unsuspend', 'Modules\Websites\Controllers\AdminWebsiteController@bandwidthUnsuspend');
            $router->post('/websites/{id}/php/dirs', 'Modules\Websites\Controllers\AdminWebsiteController@phpDirSave');
            $router->post('/websites/{id}/php/dirs/delete', 'Modules\Websites\Controllers\AdminWebsiteController@phpDirDelete');
            $router->post('/websites/{id}/rate-limit', 'Modules\Websites\Controllers\AdminWebsiteController@rateLimitSave');
        });

        $router->group(['prefix' => '/panel', 'middleware' => ['auth.customer', 'feature.websites']], function (Router $router) {
            $router->get('/websites', 'Modules\Websites\Controllers\CustomerWebsiteController@index');
            // Website creation is admin-only. This stub keeps old bookmarks
            // from falling through to show('create') (strict-types TypeError
            // → 500) — it just bounces back to the list.
            $router->get('/websites/create', function () {
                return \App\Foundation\Response::redirect('/panel/websites');
            });
            $router->get('/websites/{id}', 'Modules\Websites\Controllers\CustomerWebsiteController@show');
            $router->post('/websites/{id}/delete', 'Modules\Websites\Controllers\CustomerWebsiteController@destroy');
            $router->get('/websites/{id}/nginx', 'Modules\Websites\Controllers\CustomerWebsiteController@nginx');
            $router->post('/websites/{id}/privacy/dirs', 'Modules\Websites\Controllers\CustomerWebsiteController@privacyAddDir');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/delete', 'Modules\Websites\Controllers\CustomerWebsiteController@privacyDeleteDir');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/toggle', 'Modules\Websites\Controllers\CustomerWebsiteController@privacyToggleDir');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/users', 'Modules\Websites\Controllers\CustomerWebsiteController@privacyAddUser');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/users/{userId}/delete', 'Modules\Websites\Controllers\CustomerWebsiteController@privacyDeleteUser');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/leech', 'Modules\Websites\Controllers\CustomerWebsiteController@leechMax');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/users/{userId}/suspend', 'Modules\Websites\Controllers\CustomerWebsiteController@leechSuspend');
            $router->post('/websites/{id}/privacy/dirs/{dirId}/users/{userId}/unsuspend', 'Modules\Websites\Controllers\CustomerWebsiteController@leechUnsuspend');
            $router->get('/websites/{id}/logs/download', 'Modules\Websites\Controllers\CustomerWebsiteController@logsDownload');
            $router->post('/websites/{id}/worker/restart', 'Modules\Websites\Controllers\CustomerWebsiteController@workerRestart');
            $router->post('/websites/{id}/nginx-custom', 'Modules\Websites\Controllers\CustomerWebsiteController@customNginxSave');
            $router->post('/websites/{id}/maintenance', 'Modules\Websites\Controllers\CustomerWebsiteController@maintenanceToggle');
            $router->post('/websites/{id}/https', 'Modules\Websites\Controllers\CustomerWebsiteController@httpsSave');
            $router->post('/websites/{id}/rate-limit', 'Modules\Websites\Controllers\CustomerWebsiteController@rateLimitSave');
            $router->get('/php-versions', 'Modules\Websites\Controllers\CustomerWebsiteController@phpOverview');
            $router->get('/websites/{id}/php', 'Modules\Websites\Controllers\CustomerWebsiteController@phpSettings');
            $router->post('/websites/{id}/php', 'Modules\Websites\Controllers\CustomerWebsiteController@updatePhpSettings');
            $router->post('/websites/{id}/php/dirs', 'Modules\Websites\Controllers\CustomerWebsiteController@phpDirSave');
            $router->post('/websites/{id}/php/dirs/delete', 'Modules\Websites\Controllers\CustomerWebsiteController@phpDirDelete');
        });
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'websites',
                    'label' => 'Websites',
                    'icon' => 'globe-2',
                    'order' => 40,
                    'children' => [
                        ['id' => 'all', 'label' => 'All Websites', 'url' => '/admin/websites', 'order' => 0],
                        ['id' => 'add', 'label' => 'Add Website', 'url' => '/admin/websites/create', 'order' => 10],
                    ],
                ],
            ],
            'customer' => [
                [
                    'id' => 'websites',
                    'label' => 'Websites',
                    'icon' => 'globe-2',
                    'order' => 10,
                    'children' => [
                        ['id' => 'all', 'label' => 'My Websites', 'url' => '/panel/websites', 'order' => 0],
                        ['id' => 'php', 'label' => 'PHP Versions', 'url' => '/panel/php-versions', 'order' => 20],
                    ],
                ],
            ],
        ];
    }

    public function migrations(): array
    {
        return [
            \Modules\Websites\Migrations\CreateWebsitesTable::class,
            \Modules\Websites\Migrations\AddPhpSettings::class,
            \Modules\Websites\Migrations\CreateProtectedDirsTable::class,
            \Modules\Websites\Migrations\CreateProtectedDirUsersTable::class,
            \Modules\Websites\Migrations\AddCustomNginxToWebsites::class,
            \Modules\Websites\Migrations\AddMaintenanceModeToWebsites::class,
            \Modules\Websites\Migrations\AddRateLimitToWebsites::class,
            \Modules\Websites\Migrations\AddLeechProtection::class,
            \Modules\Websites\Migrations\AddSuspendedBandwidth::class,
            \Modules\Websites\Migrations\AddSuspendedAccountToWebsites::class,
            \Modules\Websites\Migrations\AddHttpsToWebsites::class,
            \Modules\Websites\Migrations\AddWebsitePhpDirs::class,
            \Modules\Websites\Migrations\AddIpv4ToWebsites::class,
            \Modules\Websites\Migrations\AddConnLimitToWebsites::class,
        ];
    }
}