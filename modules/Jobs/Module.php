<?php
declare(strict_types=1);
namespace Modules\Jobs;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'jobs'; }
    public function name(): string { return 'Jobs'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return false; }
    public function requires(): array { return ['core']; }
    public function permissions(): array { return ['jobs.view','jobs.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:jobs.view']], function(Router $r) {
            $r->get('/jobs','Modules\Jobs\Controllers\JobController@index');
            $r->post('/jobs/{id}/retry','Modules\Jobs\Controllers\JobController@retry');
            $r->post('/jobs/{id}/cancel','Modules\Jobs\Controllers\JobController@cancel');
        });
    }
    public function navigation(): array {
        return ['admin'=>[['id'=>'jobs','label'=>'Jobs','icon'=>'list-checks','order'=>105,'children'=>[['id'=>'all','label'=>'Jobs','url'=>'/admin/jobs','order'=>0]]]]];
    }
    public function migrations(): array { return [\Modules\Jobs\Migrations\CreateJobsTable::class, \Modules\Jobs\Migrations\AddScheduleToJobs::class]; }
}