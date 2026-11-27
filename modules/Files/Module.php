<?php
declare(strict_types=1);
namespace Modules\Files;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'files'; }
    public function name(): string { return 'Files'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts']; }
    public function permissions(): array { return ['files.view','files.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:files.view']], function(Router $r) {
            $r->get('/files','Modules\Files\Controllers\AdminFilesController@index');
            $r->post('/files/{account}/mkdir','Modules\Files\Controllers\AdminFilesController@mkdir');
            $r->post('/files/{account}/delete','Modules\Files\Controllers\AdminFilesController@delete');
            $r->post('/files/{account}/rename','Modules\Files\Controllers\AdminFilesController@rename');
            $r->post('/files/{account}/copy','Modules\Files\Controllers\AdminFilesController@copy');
            $r->post('/files/{account}/chmod','Modules\Files\Controllers\AdminFilesController@chmod');
            $r->post('/files/{account}/extract','Modules\Files\Controllers\AdminFilesController@extract');
            $r->post('/files/{account}/create','Modules\Files\Controllers\AdminFilesController@create');
            $r->post('/files/{account}/upload','Modules\Files\Controllers\AdminFilesController@upload');
            $r->post('/files/{account}/archive','Modules\Files\Controllers\AdminFilesController@archive');
            $r->get('/files/{account}/edit','Modules\Files\Controllers\AdminFilesController@edit');
            $r->post('/files/{account}/edit','Modules\Files\Controllers\AdminFilesController@save');
            $r->get('/files/{account}/download','Modules\Files\Controllers\AdminFilesController@download');
        });
        $router->group(['prefix'=>'/panel','middleware'=>['auth.customer', 'feature.files']], function(Router $r) {
            $r->get('/files','Modules\Files\Controllers\CustomerFilesController@index');
            $r->post('/files/mkdir','Modules\Files\Controllers\CustomerFilesController@mkdir');
            $r->post('/files/delete','Modules\Files\Controllers\CustomerFilesController@delete');
            $r->post('/files/rename','Modules\Files\Controllers\CustomerFilesController@rename');
            $r->post('/files/copy','Modules\Files\Controllers\CustomerFilesController@copy');
            $r->post('/files/chmod','Modules\Files\Controllers\CustomerFilesController@chmod');
            $r->post('/files/extract','Modules\Files\Controllers\CustomerFilesController@extract');
            $r->post('/files/create','Modules\Files\Controllers\CustomerFilesController@create');
            $r->post('/files/upload','Modules\Files\Controllers\CustomerFilesController@upload');
            $r->post('/files/archive','Modules\Files\Controllers\CustomerFilesController@archive');
            $r->get('/files/edit','Modules\Files\Controllers\CustomerFilesController@edit');
            $r->post('/files/edit','Modules\Files\Controllers\CustomerFilesController@save');
            $r->get('/files/download','Modules\Files\Controllers\CustomerFilesController@download');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'files','label'=>'Files','icon'=>'folder','order'=>85,'children'=>[['id'=>'file-manager','label'=>'File Manager','url'=>'/admin/files','order'=>0]]]],
            'customer'=>[['id'=>'files','label'=>'Files','icon'=>'folder','order'=>8,'children'=>[['id'=>'file-manager','label'=>'File Manager','url'=>'/panel/files','order'=>0]]]],
        ];
    }
}