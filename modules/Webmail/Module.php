<?php
declare(strict_types=1);
namespace Modules\Webmail;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'webmail'; }
    public function name(): string { return 'Webmail'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return false; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts','email']; }
    public function permissions(): array { return ['webmail.view']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/panel/webmail','middleware'=>['auth.customer', 'feature.webmail']], function(Router $r) {
            $r->get('/', 'Modules\Webmail\Controllers\CustomerWebmailController@index');
            $r->get('/open/{id}', 'Modules\Webmail\Controllers\CustomerWebmailController@openMailbox');
            $r->get('/roundcube/{id}', 'Modules\Webmail\Controllers\CustomerWebmailController@roundcube');
            $r->post('/login', 'Modules\Webmail\Controllers\CustomerWebmailController@login');
            $r->get('/inbox', 'Modules\Webmail\Controllers\CustomerWebmailController@inbox');
            $r->get('/folder', 'Modules\Webmail\Controllers\CustomerWebmailController@folder');
            $r->get('/read/{uid}', 'Modules\Webmail\Controllers\CustomerWebmailController@read');
            $r->get('/read/{uid}/attachment/{index}', 'Modules\Webmail\Controllers\CustomerWebmailController@downloadAttachment');
            $r->post('/move', 'Modules\Webmail\Controllers\CustomerWebmailController@move');
            $r->post('/trash/empty', 'Modules\Webmail\Controllers\CustomerWebmailController@emptyTrash');
            $r->get('/compose', 'Modules\Webmail\Controllers\CustomerWebmailController@compose');
            $r->get('/compose/{uid}', 'Modules\Webmail\Controllers\CustomerWebmailController@reply');
            $r->post('/send', 'Modules\Webmail\Controllers\CustomerWebmailController@send');
            $r->post('/delete', 'Modules\Webmail\Controllers\CustomerWebmailController@delete');
            $r->post('/read/{uid}/flag', 'Modules\Webmail\Controllers\CustomerWebmailController@flag');
            $r->post('/logout', 'Modules\Webmail\Controllers\CustomerWebmailController@logout');
        });
    }
    public function navigation(): array {
        return [
            'customer'=>[['id'=>'webmail','label'=>'Webmail','icon'=>'mail','order'=>21,'children'=>[['id'=>'inbox','label'=>'Webmail','url'=>'/panel/webmail','order'=>0]]]],
        ];
    }
    public function migrations(): array { return []; }
}