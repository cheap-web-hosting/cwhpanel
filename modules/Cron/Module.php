<?php
declare(strict_types=1);
namespace Modules\Cron;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'cron'; }
    public function name(): string { return 'Cron Jobs'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts']; }
    public function permissions(): array { return ['cron.view','cron.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:cron.view']], function(Router $r) {
            $r->get('/cron','Modules\Cron\Controllers\AdminCronController@index');
            $r->get('/cron/create','Modules\Cron\Controllers\AdminCronController@create');
            $r->post('/cron','Modules\Cron\Controllers\AdminCronController@store');
            $r->post('/cron/{id}/delete','Modules\Cron\Controllers\AdminCronController@destroy');
            $r->post('/cron/{id}/toggle','Modules\Cron\Controllers\AdminCronController@toggle');
            $r->post('/cron/{id}/run','Modules\Cron\Controllers\AdminCronController@runNow');
        });
        $router->group(['prefix'=>'/panel','middleware'=>['auth.customer', 'feature.cron']], function(Router $r) {
            $r->get('/cron','Modules\Cron\Controllers\CustomerCronController@index');
            $r->get('/cron/create','Modules\Cron\Controllers\CustomerCronController@create');
            $r->post('/cron','Modules\Cron\Controllers\CustomerCronController@store');
            $r->post('/cron/{id}/delete','Modules\Cron\Controllers\CustomerCronController@destroy');
            $r->post('/cron/{id}/toggle','Modules\Cron\Controllers\CustomerCronController@toggle');
            $r->post('/cron/{id}/run','Modules\Cron\Controllers\CustomerCronController@runNow');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'cron','label'=>'Cron Jobs','icon'=>'clock-3','order'=>75,'children'=>[['id'=>'list','label'=>'Cron Jobs','url'=>'/admin/cron','order'=>0],['id'=>'add','label'=>'Add Cron Job','url'=>'/admin/cron/create','order'=>10]]]],
            'customer'=>[['id'=>'cron','label'=>'Cron Jobs','icon'=>'clock-3','order'=>30,'children'=>[['id'=>'list','label'=>'Cron Jobs','url'=>'/panel/cron','order'=>0],['id'=>'add','label'=>'Add Cron Job','url'=>'/panel/cron/create','order'=>10]]]],
        ];
    }
    public function migrations(): array { return [\Modules\Cron\Migrations\CreateCronJobsTable::class,\Modules\Cron\Migrations\AddCronRunStats::class,\Modules\Cron\Migrations\AddJailedToCronJobs::class]; }
}