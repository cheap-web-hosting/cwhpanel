<?php
declare(strict_types=1);
namespace Modules\Applications;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'applications'; }
    public function name(): string { return 'Applications'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts']; }
    public function permissions(): array { return ['apps.view','apps.create','apps.delete','apps.manage']; }
    public function routes(Router $router): void {
        // Inbound git-forge receiver: public by necessity (no panel session),
        // authenticated by per-app HMAC secret. /api/* skips the CSRF gate.
        $router->post('/api/hooks/apps/{id}', 'Modules\Applications\Controllers\WebhookController@receive');
        $admin = function (Router $r, string $prefix, string $kind) {
            $c = 'Modules\Applications\Controllers\AdminApplicationController@';
            $r->get($prefix, $c . $kind . 'Index');
            $r->get($prefix . '/create', $c . $kind . 'Create');
            $r->post($prefix, $c . $kind . 'Store');
            $r->get($prefix . '/{id}', $c . $kind . 'Show');
            $r->post($prefix . '/{id}/delete', $c . $kind . 'Destroy');
            $r->post($prefix . '/{id}/start', $c . $kind . 'Start');
            $r->post($prefix . '/{id}/stop', $c . $kind . 'Stop');
            $r->post($prefix . '/{id}/restart', $c . $kind . 'Restart');
            $r->post($prefix . '/{id}/deploy', $c . $kind . 'Deploy');
            $r->post($prefix . '/{id}/webhook', $c . $kind . 'Webhook');
            $r->post($prefix . '/{id}/webhook/rotate', $c . $kind . 'WebhookRotate');
            $r->post($prefix . '/{id}/env', $c . $kind . 'Env');
            $r->post($prefix . '/{id}/update', $c . $kind . 'Update');
            $r->post($prefix . '/{id}/clone', $c . $kind . 'Clone');
            $r->post($prefix . '/{id}/sync', $c . $kind . 'Sync');
            if ($kind === 'python') {
                $r->post($prefix . '/{id}/packages', $c . 'pythonPackageInstall');
                $r->post($prefix . '/{id}/packages/remove', $c . 'pythonPackageRemove');
            }
        };
        $customer = function (Router $r, string $prefix, string $kind) {
            $c = 'Modules\Applications\Controllers\CustomerApplicationController@';
            $r->get($prefix, $c . $kind . 'Index');
            $r->get($prefix . '/create', $c . $kind . 'Create');
            $r->post($prefix, $c . $kind . 'Store');
            $r->get($prefix . '/{id}', $c . $kind . 'Show');
            $r->post($prefix . '/{id}/delete', $c . $kind . 'Destroy');
            $r->post($prefix . '/{id}/start', $c . $kind . 'Start');
            $r->post($prefix . '/{id}/stop', $c . $kind . 'Stop');
            $r->post($prefix . '/{id}/restart', $c . $kind . 'Restart');
            $r->post($prefix . '/{id}/deploy', $c . $kind . 'Deploy');
            $r->post($prefix . '/{id}/webhook', $c . $kind . 'Webhook');
            $r->post($prefix . '/{id}/webhook/rotate', $c . $kind . 'WebhookRotate');
            $r->post($prefix . '/{id}/env', $c . $kind . 'Env');
            $r->post($prefix . '/{id}/update', $c . $kind . 'Update');
            $r->post($prefix . '/{id}/clone', $c . $kind . 'Clone');
            $r->post($prefix . '/{id}/sync', $c . $kind . 'Sync');
            if ($kind === 'python') {
                $r->post($prefix . '/{id}/packages', $c . 'pythonPackageInstall');
                $r->post($prefix . '/{id}/packages/remove', $c . 'pythonPackageRemove');
            }
        };
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:apps.view']], function(Router $r) use ($admin) {
            $admin($r, '/nodejs', 'node');
            $admin($r, '/python', 'python');
            $r->get('/runtimes','Modules\Applications\Controllers\AdminApplicationController@runtimes');
            $r->post('/runtimes/install','Modules\Applications\Controllers\AdminApplicationController@installRuntime');
            // Legacy combined Applications URLs: redirect to the runtime page.
            $c = 'Modules\Applications\Controllers\AdminApplicationController@legacy';
            $r->get('/applications', $c . 'Index');
            $r->get('/applications/create', $c . 'Create');
            $r->post('/applications', $c . 'Store');
            $r->get('/applications/{id}', $c . 'Show');
            $r->post('/applications/{id}/delete', $c . 'Destroy');
            $r->post('/applications/{id}/start', $c . 'Start');
            $r->post('/applications/{id}/stop', $c . 'Stop');
            $r->post('/applications/{id}/restart', $c . 'Restart');
            $r->post('/applications/{id}/deploy', $c . 'Deploy');
        });
        $router->group(['prefix'=>'/panel','middleware'=>['auth.customer', 'feature.applications']], function(Router $r) use ($customer) {
            $customer($r, '/nodejs', 'node');
            $customer($r, '/python', 'python');
            // Legacy combined Applications URLs: redirect to the runtime page.
            $c = 'Modules\Applications\Controllers\CustomerApplicationController@legacy';
            $r->get('/applications', $c . 'Index');
            $r->get('/applications/create', $c . 'Create');
            $r->post('/applications', $c . 'Store');
            $r->get('/applications/{id}', $c . 'Show');
            $r->post('/applications/{id}/delete', $c . 'Destroy');
            $r->post('/applications/{id}/start', $c . 'Start');
            $r->post('/applications/{id}/stop', $c . 'Stop');
            $r->post('/applications/{id}/restart', $c . 'Restart');
            $r->post('/applications/{id}/deploy', $c . 'Deploy');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'nodejs','label'=>'Node.js','icon'=>'braces','order'=>70,'children'=>[['id'=>'all','label'=>'Node.js Apps','url'=>'/admin/nodejs','order'=>0],['id'=>'add','label'=>'Add Node.js App','url'=>'/admin/nodejs/create','order'=>10]]],
                ['id'=>'python','label'=>'Python','icon'=>'cpu','order'=>71,'children'=>[['id'=>'all','label'=>'Python Apps','url'=>'/admin/python','order'=>0],['id'=>'add','label'=>'Add Python App','url'=>'/admin/python/create','order'=>10]]],
                ['id'=>'runtimes','label'=>'Runtimes','icon'=>'server','order'=>72,'children'=>[['id'=>'all','label'=>'Runtimes','url'=>'/admin/runtimes','order'=>0]]]],
            'customer'=>[['id'=>'nodejs','label'=>'Node.js','icon'=>'braces','order'=>28,'children'=>[['id'=>'all','label'=>'My Node.js Apps','url'=>'/panel/nodejs','order'=>0],['id'=>'add','label'=>'Add Node.js App','url'=>'/panel/nodejs/create','order'=>10]]],
                ['id'=>'python','label'=>'Python','icon'=>'cpu','order'=>29,'children'=>[['id'=>'all','label'=>'My Python Apps','url'=>'/panel/python','order'=>0],['id'=>'add','label'=>'Add Python App','url'=>'/panel/python/create','order'=>10]]]],
        ];
    }
    public function migrations(): array { return [\Modules\Applications\Migrations\CreateApplicationsTable::class,\Modules\Applications\Migrations\AddRuntimeVersionToApplications::class,\Modules\Applications\Migrations\AddProxyToApplications::class,\Modules\Applications\Migrations\AddEnvToApplications::class,\Modules\Applications\Migrations\AddWebhookToApplications::class,\Modules\Applications\Migrations\AddAppStaging::class,\Modules\Applications\Migrations\CreateWebhookDeliveriesTable::class]; }
}