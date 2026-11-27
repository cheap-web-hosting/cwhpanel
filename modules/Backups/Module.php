<?php
declare(strict_types=1);
namespace Modules\Backups;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'backups'; }
    public function name(): string { return 'Backups'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts']; }
    public function permissions(): array { return ['backups.view','backups.create','backups.restore','backups.delete']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:backups.view']], function(Router $r) {
            $r->get('/backups','Modules\Backups\Controllers\AdminBackupController@index');
            $r->get('/backups/create','Modules\Backups\Controllers\AdminBackupController@create');
            $r->post('/backups','Modules\Backups\Controllers\AdminBackupController@store');
            $r->get('/backups/{id}/download','Modules\Backups\Controllers\AdminBackupController@download');
            $r->post('/backups/{id}/delete','Modules\Backups\Controllers\AdminBackupController@destroy');
            $r->post('/backups/{id}/restore','Modules\Backups\Controllers\AdminBackupController@restore');
            $r->get('/backups/plan','Modules\Backups\Controllers\AdminBackupController@plan');
            $r->post('/backups/plan','Modules\Backups\Controllers\AdminBackupController@planSave')->withMiddleware('stepup');
            $r->post('/backups/plan/run','Modules\Backups\Controllers\AdminBackupController@planRun')->withMiddleware('stepup');
        });
        $router->group(['prefix'=>'/panel','middleware'=>['auth.customer', 'feature.backups']], function(Router $r) {
            $r->get('/backups','Modules\Backups\Controllers\CustomerBackupController@index');
            $r->get('/backups/create','Modules\Backups\Controllers\CustomerBackupController@create');
            $r->post('/backups','Modules\Backups\Controllers\CustomerBackupController@store');
            $r->post('/backups/encryption','Modules\Backups\Controllers\CustomerBackupController@encryptionToggle');
            $r->get('/backups/{id}/download','Modules\Backups\Controllers\CustomerBackupController@download');
            $r->post('/backups/{id}/delete','Modules\Backups\Controllers\CustomerBackupController@destroy');
            $r->post('/backups/{id}/restore','Modules\Backups\Controllers\CustomerBackupController@restore');
            $r->post('/backups/{id}/restore-remote','Modules\Backups\Controllers\CustomerBackupController@restoreRemote');
            $r->post('/backups/copies/{copyId}/fetch','Modules\Backups\Controllers\CustomerBackupController@fetchCopy');
            $r->get('/backups/schedules','Modules\Backups\Controllers\CustomerBackupController@schedules');
            $r->post('/backups/schedules','Modules\Backups\Controllers\CustomerBackupController@scheduleStore');
            $r->post('/backups/schedules/{id}/toggle','Modules\Backups\Controllers\CustomerBackupController@scheduleToggle');
            $r->post('/backups/schedules/{id}/delete','Modules\Backups\Controllers\CustomerBackupController@scheduleDestroy');
            $r->post('/backups/schedules/{id}/run','Modules\Backups\Controllers\CustomerBackupController@scheduleRun');
            $r->get('/backups/remotes','Modules\Backups\Controllers\CustomerBackupController@remotes');
            $r->post('/backups/remotes','Modules\Backups\Controllers\CustomerBackupController@remoteStore');
            $r->post('/backups/remotes/{id}/test','Modules\Backups\Controllers\CustomerBackupController@remoteTest');
            $r->post('/backups/remotes/{id}/delete','Modules\Backups\Controllers\CustomerBackupController@remoteDestroy');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'backups','label'=>'Backups','icon'=>'archive','order'=>80,'children'=>[['id'=>'all','label'=>'Backups','url'=>'/admin/backups','order'=>0],['id'=>'add','label'=>'Create Backup','url'=>'/admin/backups/create','order'=>10]]]],
            'customer'=>[['id'=>'backups','label'=>'Backups','icon'=>'archive','order'=>32,'children'=>[['id'=>'all','label'=>'Backups','url'=>'/panel/backups','order'=>0],['id'=>'add','label'=>'Create Backup','url'=>'/panel/backups/create','order'=>10],['id'=>'schedules','label'=>'Schedules','url'=>'/panel/backups/schedules','order'=>20],['id'=>'remotes','label'=>'Remote Storage','url'=>'/panel/backups/remotes','order'=>30]]]],
        ];
    }
    public function migrations(): array { return [\Modules\Backups\Migrations\CreateBackupsTable::class, \Modules\Backups\Migrations\CreateScheduleTables::class, \Modules\Backups\Migrations\CreateBackupPlans::class]; }

    /** Fleet backup plan runs in the worker (one job per plan sweep). */
    public function jobs(): array
    {
        return [
            'backup.plan.run' => static function (array $payload): string {
                $id = (int) ($payload['plan_id'] ?? 0);
                return app(\Modules\Backups\Services\BackupService::class)->runPlan($id);
            },
        ];
    }
}