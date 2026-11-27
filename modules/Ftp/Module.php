<?php
declare(strict_types=1);
namespace Modules\Ftp;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'ftp'; }
    public function name(): string { return 'FTP'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts']; }
    public function permissions(): array { return ['ftp.view','ftp.accounts.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:ftp.view']], function(Router $r) {
            $r->get('/ftp','Modules\Ftp\Controllers\AdminFtpController@index');
            $r->get('/ftp/create','Modules\Ftp\Controllers\AdminFtpController@create');
            $r->post('/ftp','Modules\Ftp\Controllers\AdminFtpController@store');
            $r->post('/ftp/{id}/delete','Modules\Ftp\Controllers\AdminFtpController@destroy');
            $r->post('/ftp/{id}/suspend','Modules\Ftp\Controllers\AdminFtpController@suspend');
            $r->post('/ftp/{id}/unsuspend','Modules\Ftp\Controllers\AdminFtpController@unsuspend');
            $r->post('/ftp/{id}/password','Modules\Ftp\Controllers\AdminFtpController@changePassword');
        });
        $router->group(['prefix'=>'/panel','middleware'=>['auth.customer', 'feature.ftp']], function(Router $r) {
            $r->get('/ftp','Modules\Ftp\Controllers\CustomerFtpController@index');
            $r->get('/ftp/create','Modules\Ftp\Controllers\CustomerFtpController@create');
            $r->post('/ftp','Modules\Ftp\Controllers\CustomerFtpController@store');
            $r->post('/ftp/{id}/delete','Modules\Ftp\Controllers\CustomerFtpController@destroy');
            $r->post('/ftp/{id}/password','Modules\Ftp\Controllers\CustomerFtpController@changePassword');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'ftp','label'=>'FTP','icon'=>'folder-up','order'=>65,'children'=>[['id'=>'accounts','label'=>'FTP Accounts','url'=>'/admin/ftp','order'=>0],['id'=>'add','label'=>'Add FTP Account','url'=>'/admin/ftp/create','order'=>10]]]],
            'customer'=>[['id'=>'ftp','label'=>'FTP','icon'=>'folder-up','order'=>22,'children'=>[['id'=>'accounts','label'=>'FTP Accounts','url'=>'/panel/ftp','order'=>0]]]],
        ];
    }
    public function migrations(): array { return [\Modules\Ftp\Migrations\CreateFtpAccountsTable::class]; }
}