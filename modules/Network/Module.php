<?php
declare(strict_types=1);
namespace Modules\Network;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'network'; }
    public function name(): string { return 'Network'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return false; }
    public function requires(): array { return ['core','accounts']; }
    public function permissions(): array { return ['network.view','network.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:network.view']], function(Router $r) {
            $r->get('/network','Modules\Network\Controllers\NetworkController@index');
            $r->post('/network/settings','Modules\Network\Controllers\NetworkController@saveSettings');
            $r->post('/network/pool','Modules\Network\Controllers\NetworkController@poolAdd');
            $r->post('/network/pool/{id}/delete','Modules\Network\Controllers\NetworkController@poolDelete');
        });
    }
    public function navigation(): array {
        return ['admin'=>[['id'=>'network','label'=>'Network','icon'=>'network','order'=>96,'children'=>[['id'=>'overview','label'=>'IP Management','url'=>'/admin/network','order'=>0]]]]];
    }
    public function migrations(): array { return [\Modules\Network\Migrations\CreateIpPoolTable::class,\Modules\Network\Migrations\AddPoolPrefixlen::class]; }

    /**
     * Self-heal grants: seed.php only assigns permissions that exist at
     * install time, so permissions introduced later (like these) would
     * otherwise deny even superadmin. Every boot, ensure they exist and
     * are granted to the system admin roles (INSERT IGNORE = cheap no-op
     * when converged).
     */
    public function boot(): void
    {
        if (!$this->tablesExist(['permissions', 'roles', 'role_permissions'])) {
            return;
        }
        $db = app('db');
        try {
            foreach ($this->permissions() as $name) {
                $db->statement(
                    'INSERT IGNORE INTO `permissions` (`name`, `module`, `created_at`, `updated_at`) VALUES (?, ?, NOW(), NOW())',
                    [$name, 'network']
                );
            }
            $ids = array_column(
                $db->select('SELECT `id` FROM `permissions` WHERE `name` IN (?, ?)', ['network.view', 'network.manage']),
                'id'
            );
            foreach (['superadmin', 'admin'] as $slug) {
                $role = $db->selectOne('SELECT `id` FROM `roles` WHERE `slug` = ?', [$slug]);
                if ($role === null) {
                    continue;
                }
                foreach ($ids as $pid) {
                    $db->statement(
                        'INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)',
                        [(int) $role['id'], (int) $pid]
                    );
                }
            }
        } catch (\Throwable $e) {
        }
    }
}
