<?php
declare(strict_types=1);
namespace Modules\Databases;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'databases'; }
    public function name(): string { return 'Databases'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core', 'accounts']; }
    public function permissions(): array { return ['databases.view','databases.create','databases.delete','databases.users.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix' => '/admin','middleware' => ['auth.admin', 'can:databases.view']], function (Router $router) {
            $router->get('/databases', 'Modules\Databases\Controllers\AdminDatabaseController@index');
            $router->get('/databases/create', 'Modules\Databases\Controllers\AdminDatabaseController@create');
            $router->post('/databases', 'Modules\Databases\Controllers\AdminDatabaseController@store');
            $router->get('/databases/{id}', 'Modules\Databases\Controllers\AdminDatabaseController@show');
            $router->post('/databases/{id}/delete', 'Modules\Databases\Controllers\AdminDatabaseController@destroy');
            $router->post('/databases/{id}/add-user', 'Modules\Databases\Controllers\AdminDatabaseController@addUser');
            $router->post('/databases/{id}/delete-user/{userId}', 'Modules\Databases\Controllers\AdminDatabaseController@deleteUser');
            $router->post('/databases/{id}/users/{userId}/hosts', 'Modules\Databases\Controllers\AdminDatabaseController@addHost');
            $router->post('/databases/{id}/users/{userId}/hosts/{hostId}/delete', 'Modules\Databases\Controllers\AdminDatabaseController@removeHost');
            $router->post('/databases/{id}/rotate-password', 'Modules\Databases\Controllers\AdminDatabaseController@rotatePassword');
        });
        $router->group(['prefix' => '/panel','middleware' => ['auth.customer', 'feature.databases']], function (Router $router) {
            $router->get('/databases', 'Modules\Databases\Controllers\CustomerDatabaseController@index');
            $router->get('/databases/create', 'Modules\Databases\Controllers\CustomerDatabaseController@create');
            $router->post('/databases', 'Modules\Databases\Controllers\CustomerDatabaseController@store');
            $router->get('/databases/{id}', 'Modules\Databases\Controllers\CustomerDatabaseController@show');
            $router->post('/databases/{id}/delete', 'Modules\Databases\Controllers\CustomerDatabaseController@destroy');
            $router->post('/databases/{id}/add-user', 'Modules\Databases\Controllers\CustomerDatabaseController@addUser');
            $router->post('/databases/{id}/delete-user/{userId}', 'Modules\Databases\Controllers\CustomerDatabaseController@deleteUser');
            $router->post('/databases/{id}/users/{userId}/hosts', 'Modules\Databases\Controllers\CustomerDatabaseController@addHost');
            $router->post('/databases/{id}/users/{userId}/hosts/{hostId}/delete', 'Modules\Databases\Controllers\CustomerDatabaseController@removeHost');
            $router->post('/databases/{id}/rotate-password', 'Modules\Databases\Controllers\CustomerDatabaseController@rotatePassword');
        });
    }
    public function navigation(): array {
        return [
            'admin' => [['id'=>'databases','label'=>'Databases','icon'=>'database','order'=>55,'children'=>[['id'=>'all','label'=>'All Databases','url'=>'/admin/databases','order'=>0],['id'=>'add','label'=>'Add Database','url'=>'/admin/databases/create','order'=>10],['id'=>'phpmyadmin','label'=>'phpMyAdmin','url'=>'/phpmyadmin/','order'=>20]]]],
            'customer' => [['id'=>'databases','label'=>'Databases','icon'=>'database','order'=>25,'children'=>[['id'=>'all','label'=>'My Databases','url'=>'/panel/databases','order'=>0],['id'=>'add','label'=>'Add Database','url'=>'/panel/databases/create','order'=>10],['id'=>'phpmyadmin','label'=>'phpMyAdmin','url'=>'/phpmyadmin/','order'=>20]]]],
        ];
    }
    public function migrations(): array { return [\Modules\Databases\Migrations\CreateDatabasesTable::class,\Modules\Databases\Migrations\CreateDatabaseUsersTable::class,\Modules\Databases\Migrations\CreateDatabaseRemoteHostsTable::class,\Modules\Databases\Migrations\CreateMysqlGovernorEventsTable::class]; }
}