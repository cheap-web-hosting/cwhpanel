<?php

declare(strict_types=1);

namespace Modules\Core;

use App\Foundation\Router;

class Module extends \App\Foundation\Module\Module
{
    public function id(): string
    {
        return 'core';
    }

    /**
     * Persist new Core permissions on existing installs.
     *
     * `seed.php` only runs at install time, so a permission added later (e.g.
     * `server.manage`) would never reach an existing DB and the role editor
     * could not grant it. Deliberately does NOT auto-grant it to roles that
     * hold `server.view`: the whole point is that a read-only role must be
     * given the destructive permission explicitly. Full admins (role_id NULL)
     * bypass role checks in Gate and are unaffected.
     */
    public function boot(): void
    {
        if (!$this->tablesExist(['permissions'])) {
            return;
        }
        $db = app('db');
        foreach ($this->permissions() as $perm) {
            $row = $db->selectOne('SELECT `id` FROM `permissions` WHERE `name` = ?', [$perm]);
            if ($row === null) {
                $permId = (int) $db->insert('permissions', [
                    'name' => $perm,
                    'module' => $this->id(),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $permId = (int) $row['id'];
            }

            // Grant to effectively-administrative roles only (>= half of all
            // permissions). The seeded Administrator role carries a role_id, so
            // Gate::can() looks at role_permissions — without this grant it
            // would silently lose every destructive Server action. A view-only
            // "ops" role holds a handful of permissions and is deliberately NOT
            // granted: server.manage must be assigned on purpose.
            $total = (int) ($db->scalar('SELECT COUNT(*) FROM `permissions`') ?? 0);
            if ($total <= 0) {
                continue;
            }
            foreach ($db->select('SELECT `role_id`, COUNT(*) AS `c` FROM `role_permissions` GROUP BY `role_id`') as $r) {
                if ((int) $r['c'] * 2 < $total) {
                    continue;
                }
                $granted = $db->selectOne(
                    'SELECT `id` FROM `role_permissions` WHERE `role_id` = ? AND `permission_id` = ?',
                    [(int) $r['role_id'], $permId]
                );
                if ($granted === null) {
                    $db->insert('role_permissions', [
                        'role_id' => (int) $r['role_id'],
                        'permission_id' => $permId,
                    ]);
                }
            }
        }
    }

    public function name(): string
    {
        return 'Core';
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

    public function permissions(): array
    {
        return [
            'dashboard.view',
            'server.view',
            // Destructive Server actions (PHP ini rewrite, service control,
            // reboot, updates, SSH port, hostname) must NOT be reachable with a
            // view-only role. Gate::canMutating() deliberately falls open when a
            // module declares no mutating permission, and server.view was the
            // only server.* permission — so a "read-only ops" role could rewrite
            // a version-global php.ini and run code in every tenant on it.
            'server.manage',
        ];
    }

    public function routes(Router $router): void
    {
        $router->group(['prefix' => '/admin', 'middleware' => ['auth.admin']], function (Router $router) {
            // The landing page doubles as the denial target (deny() sends
            // rejected users here with a flash explaining why), so it carries
            // NO can: gate — it forwards the permissionless to their first
            // granted section itself, and would otherwise bounce them back
            // here forever (can:dashboard.view denies → deny() → /admin).
            $router->get('/', 'Modules\Core\Controllers\DashboardController@landing');
            $router->group(['middleware' => ['can:server.view']], function (Router $router) {
            $router->get('/server', 'Modules\Core\Controllers\ServerController@overview');
            $router->get('/server/system', 'Modules\Core\Controllers\ServerController@system');
            $router->get('/server/php-versions', 'Modules\Core\Controllers\ServerController@phpVersions');
            $router->get('/server/php-config/{version}', 'Modules\Core\Controllers\ServerController@phpConfig');
            $router->get('/server/services', 'Modules\Core\Controllers\ServerController@services');
            $router->get('/server/nginx', 'Modules\Core\Controllers\ServerController@nginx');
            $router->get('/server/hostname', 'Modules\Core\Controllers\ServerController@hostname');
            $router->get('/server/updates', 'Modules\Core\Controllers\ServerController@updates');
            $router->get('/server/ssh', 'Modules\Core\Controllers\ServerController@ssh');

            // Everything that CHANGES host state needs server.manage on top of
            // server.view: a role granted only the view permission must not be
            // able to rewrite a version-global php.ini (code execution in every
            // tenant on that PHP), stop sshd, or reboot the box.
            $router->group(['middleware' => ['can:server.manage']], function (Router $router) {
            $router->post('/server/php-versions/install', 'Modules\Core\Controllers\ServerController@installPhpVersion')->withMiddleware('stepup');
            $router->post('/server/php-versions/uninstall', 'Modules\Core\Controllers\ServerController@uninstallPhpVersion')->withMiddleware('stepup');
            $router->post('/server/php-config/{version}/ini', 'Modules\Core\Controllers\ServerController@phpConfigIniSave')->withMiddleware('stepup');
            $router->post('/server/php-config/{version}/functions', 'Modules\Core\Controllers\ServerController@phpConfigFunctionsSave')->withMiddleware('stepup');
            $router->post('/server/services/control', 'Modules\Core\Controllers\ServerController@controlService')->withMiddleware('stepup');
            $router->post('/server/nginx/action', 'Modules\Core\Controllers\ServerController@nginxAction')->withMiddleware('stepup');
            $router->post('/server/hostname/change', 'Modules\Core\Controllers\ServerController@hostnameChange')->withMiddleware('stepup');
            $router->post('/server/hostname/reprovision', 'Modules\Core\Controllers\ServerController@hostnameReprovision')->withMiddleware('stepup');
            $router->post('/server/updates/mode', 'Modules\Core\Controllers\ServerController@updateMode')->withMiddleware('stepup');
            $router->post('/server/updates/check', 'Modules\Core\Controllers\ServerController@checkUpdates')->withMiddleware('stepup');
            $router->post('/server/updates/apply', 'Modules\Core\Controllers\ServerController@applyUpdates')->withMiddleware('stepup');
            $router->post('/server/updates/reboot', 'Modules\Core\Controllers\ServerController@rebootServer')->withMiddleware('stepup');
            $router->post('/server/updates/root-forward', 'Modules\Core\Controllers\ServerController@rootForward')->withMiddleware('stepup');
            $router->post('/server/updates/panel-check', 'Modules\Core\Controllers\ServerController@panelCheck')->withMiddleware('stepup');
            $router->post('/server/updates/panel-apply', 'Modules\Core\Controllers\ServerController@panelApply')->withMiddleware('stepup');
            $router->post('/server/updates/panel-settings', 'Modules\Core\Controllers\ServerController@panelSettings')->withMiddleware('stepup');
            $router->post('/server/ssh/port', 'Modules\Core\Controllers\ServerController@sshPortSave')->withMiddleware('stepup');
            }); // can:server.manage — destroy-only, nested inside server.view
            }); // can:server.view — nested groups merge middleware (see Router::group)
        });

        $router->group(['prefix' => '/panel', 'middleware' => ['auth.customer']], function (Router $router) {
            $router->get('/', 'Modules\Core\Controllers\CustomerDashboardController@index');
        });

        // In-app notifications API (admin + customer). Auth is checked in the
        // controller so one route works for both panels.
        $router->get('/api/notifications', 'Modules\Core\Controllers\NotificationController@index');
        $router->post('/api/notifications/{id}/read', 'Modules\Core\Controllers\NotificationController@read');
        $router->post('/api/notifications/read-all', 'Modules\Core\Controllers\NotificationController@readAll');
    }

    public function navigation(): array
    {
        return [
            'admin' => [
                [
                    'id' => 'dashboard',
                    'label' => 'Dashboard',
                    'icon' => 'layout-dashboard',
                    'url' => '/admin',
                    'order' => 0,
                ],
                [
                    'id' => 'server',
                    'label' => 'Server',
                    'icon' => 'server',
                    'order' => 10,
                    'children' => [
                        ['id' => 'overview', 'label' => 'Overview', 'url' => '/admin/server', 'order' => 0],
                        ['id' => 'hostname', 'label' => 'Hostname', 'url' => '/admin/server/hostname', 'order' => 6],
                        ['id' => 'php', 'label' => 'PHP Versions', 'url' => '/admin/server/php-versions', 'order' => 5],
                        ['id' => 'services', 'label' => 'Services', 'url' => '/admin/server/services', 'order' => 8],
                        ['id' => 'nginx', 'label' => 'Nginx', 'url' => '/admin/server/nginx', 'order' => 9],
                        ['id' => 'updates', 'label' => 'Updates', 'url' => '/admin/server/updates', 'order' => 11],
                        ['id' => 'ssh', 'label' => 'SSH', 'url' => '/admin/server/ssh', 'order' => 12],
                        ['id' => 'system', 'label' => 'System Information', 'url' => '/admin/server/system', 'order' => 10],
                    ],
                ],
            ],
            'customer' => [
                [
                    'id' => 'dashboard',
                    'label' => 'Dashboard',
                    'icon' => 'layout-dashboard',
                    'url' => '/panel',
                    'order' => 0,
                ],
            ],
        ];
    }

    public function migrations(): array
    {
        return [
            \Modules\Core\Migrations\CreateModulesTable::class,
            \Modules\Core\Migrations\CreateSessionsTable::class,
            \Modules\Core\Migrations\CreateAuditLogsTable::class,
            \Modules\Core\Migrations\CreateSettingsTable::class,
            \Modules\Core\Migrations\CreateProvisioningLogsTable::class,
            \Modules\Core\Migrations\CreateNginxWorkersTable::class,
            \Modules\Core\Migrations\AddSessionUserIndex::class,
            \Modules\Core\Migrations\AddSessionCreatedAt::class,
            \Modules\Core\Migrations\CreateNotificationsTable::class,
        ];
    }
}
