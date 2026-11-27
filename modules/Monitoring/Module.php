<?php
declare(strict_types=1);
namespace Modules\Monitoring;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'monitoring'; }
    public function name(): string { return 'Monitoring'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts']; }
    public function permissions(): array { return ['monitoring.view','monitoring.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:monitoring.view']], function(Router $r) {
            $r->get('/monitoring','Modules\Monitoring\Controllers\MonitoringController@adminIndex');
            $r->get('/monitoring/network','Modules\Monitoring\Controllers\MonitoringController@network');
        });
        $router->group(['prefix'=>'/panel','middleware'=>['auth.customer', 'feature.monitoring']], function(Router $r) {
            $r->get('/monitoring','Modules\Monitoring\Controllers\MonitoringController@customerIndex');
        });
    }
    public function migrations(): array { return []; }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'monitoring','label'=>'Monitoring','icon'=>'activity','order'=>90,'children'=>[['id'=>'overview','label'=>'Resource Usage','url'=>'/admin/monitoring','order'=>0],['id'=>'network','label'=>'Network & Bandwidth','url'=>'/admin/monitoring/network','order'=>5]]]],
            'customer'=>[['id'=>'monitoring','label'=>'Metrics','icon'=>'chart-no-axes-combined','order'=>40,'children'=>[['id'=>'resources','label'=>'Resource Usage','url'=>'/panel/monitoring','order'=>0]]]],
        ];
    }
}