<?php
declare(strict_types=1);
namespace Modules\Api;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'api'; }
    public function name(): string { return 'API'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core']; }
    public function permissions(): array { return ['api.keys.view','api.keys.manage','api.webhooks.view','api.webhooks.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:api.keys.view']], function(Router $r) {
            $r->get('/api','Modules\Api\Controllers\AdminApiController@index');
        });
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:api.webhooks.view']], function(Router $r) {
            $r->get('/api/webhooks','Modules\Api\Controllers\AdminApiController@webhooks');
        });
        // Mutating key/webhook routes require their own manage permission (a
        // view-only role must not be able to mint or revoke credentials).
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:api.keys.manage']], function(Router $r) {
            $r->post('/api/keys','Modules\Api\Controllers\AdminApiController@storeKey')->withMiddleware('stepup');
            $r->post('/api/keys/{id}/revoke','Modules\Api\Controllers\AdminApiController@revokeKey')->withMiddleware('stepup');
        });
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:api.webhooks.manage']], function(Router $r) {
            $r->post('/api/webhooks','Modules\Api\Controllers\AdminApiController@storeWebhook');
            $r->post('/api/webhooks/{id}/delete','Modules\Api\Controllers\AdminApiController@deleteWebhook');
        });
        // Public API v1 (external clients: billing software, scripts).
        // Bearer-token auth only — no session, no CSRF. Administrator keys
        // manage accounts; non-admin key owners are refused by the middleware.
        $router->group(['prefix'=>'/api/v1','middleware'=>['auth.api_key']], function(Router $r) {
            $r->get('/packages','Modules\Api\Controllers\ApiV1Controller@packages');
            $r->post('/accounts','Modules\Api\Controllers\ApiV1Controller@createAccount');
            $r->get('/accounts/{username}','Modules\Api\Controllers\ApiV1Controller@account');
            $r->post('/accounts/{username}/suspend','Modules\Api\Controllers\ApiV1Controller@suspendAccount');
            $r->post('/accounts/{username}/unsuspend','Modules\Api\Controllers\ApiV1Controller@unsuspendAccount');
            $r->post('/accounts/{username}/terminate','Modules\Api\Controllers\ApiV1Controller@terminateAccount');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'api','label'=>'API','icon'=>'braces','order'=>110,'children'=>[['id'=>'keys','label'=>'API Keys','url'=>'/admin/api','order'=>0],['id'=>'webhooks','label'=>'Webhooks','url'=>'/admin/api/webhooks','order'=>10]]]],
        ];
    }
    public function migrations(): array { return [\Modules\Api\Migrations\CreateApiKeysTable::class,\Modules\Api\Migrations\CreateWebhooksTable::class, \Modules\Api\Migrations\RevokeCustomerApiKeys::class]; }
}