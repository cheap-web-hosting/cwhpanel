<?php
declare(strict_types=1);
namespace Modules\Email;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'email'; }
    public function name(): string { return 'Email'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts','domains']; }
    public function permissions(): array { return ['email.view','email.domains.manage','email.mailboxes.manage','email.forwarders.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:email.view']], function(Router $r) {
            $r->get('/email','Modules\Email\Controllers\AdminEmailController@index');
            $r->get('/email/domains/create','Modules\Email\Controllers\AdminEmailController@createDomain');
            $r->post('/email/domains','Modules\Email\Controllers\AdminEmailController@storeDomain');
            $r->get('/email/domains/{id}','Modules\Email\Controllers\AdminEmailController@showDomain');
            $r->post('/email/domains/{id}/delete','Modules\Email\Controllers\AdminEmailController@destroyDomain');
            $r->post('/email/domains/{id}/mailbox','Modules\Email\Controllers\AdminEmailController@addMailbox');
            $r->post('/email/domains/{id}/mailbox/{mid}/delete','Modules\Email\Controllers\AdminEmailController@deleteMailbox');
            $r->post('/email/domains/{id}/mailbox/{mid}/password','Modules\Email\Controllers\AdminEmailController@changePassword');
            $r->post('/email/domains/{id}/forwarder','Modules\Email\Controllers\AdminEmailController@addForwarder');
            $r->post('/email/domains/{id}/catchall','Modules\Email\Controllers\AdminEmailController@setCatchall');
            $r->post('/email/domains/{id}/forwarder/{fid}/delete','Modules\Email\Controllers\AdminEmailController@deleteForwarder');
            $r->post('/email/domains/{id}/mailing-list','Modules\Email\Controllers\AdminEmailController@addList');
            $r->post('/email/domains/{id}/mailing-list/{lid}/delete','Modules\Email\Controllers\AdminEmailController@deleteList');
            $r->post('/email/domains/{id}/mailing-list/{lid}/posting','Modules\Email\Controllers\AdminEmailController@setListPosting');
            $r->post('/email/domains/{id}/mailing-list/{lid}/member','Modules\Email\Controllers\AdminEmailController@addListMember');
            $r->post('/email/domains/{id}/mailing-list/{lid}/member/{mid}/delete','Modules\Email\Controllers\AdminEmailController@removeListMember');
            $r->get('/email/queue','Modules\Email\Controllers\AdminEmailController@queue');
            $r->post('/email/queue/flush','Modules\Email\Controllers\AdminEmailController@queueFlush');
            $r->post('/email/queue/{id}/delete','Modules\Email\Controllers\AdminEmailController@queueDelete');
            $r->post('/email/queue/{id}/requeue','Modules\Email\Controllers\AdminEmailController@queueRequeue');
            $r->get('/email/mailbox/{mid}/filters','Modules\Email\Controllers\AdminEmailController@mailboxFilters');
            $r->post('/email/mailbox/{mid}/filters','Modules\Email\Controllers\AdminEmailController@mailboxFiltersStore');
            $r->post('/email/mailbox/{mid}/filters/rules','Modules\Email\Controllers\AdminEmailController@mailboxFilterAdd');
            $r->post('/email/mailbox/{mid}/filters/rules/{fid}/delete','Modules\Email\Controllers\AdminEmailController@mailboxFilterDelete');
        });
        $router->group(['prefix'=>'/panel','middleware'=>['auth.customer', 'feature.email']], function(Router $r) {
            $r->get('/email','Modules\Email\Controllers\CustomerEmailController@index');
            $r->get('/email/mailboxes','Modules\Email\Controllers\CustomerEmailController@mailboxes');
            $r->get('/email/forwarders','Modules\Email\Controllers\CustomerEmailController@forwarders');
            $r->post('/email/forwarders','Modules\Email\Controllers\CustomerEmailController@storeForwarder');
            $r->get('/email/catch-all','Modules\Email\Controllers\CustomerEmailController@catchall');
            $r->get('/email/lists','Modules\Email\Controllers\CustomerEmailController@lists');
            $r->post('/email/lists','Modules\Email\Controllers\CustomerEmailController@storeList');
            $r->post('/email/accounts','Modules\Email\Controllers\CustomerEmailController@storeAccount');
            $r->get('/email/domains/create','Modules\Email\Controllers\CustomerEmailController@createRedirect');
            $r->get('/email/domains/{id}','Modules\Email\Controllers\CustomerEmailController@showDomain');
            $r->post('/email/domains/{id}/mailbox','Modules\Email\Controllers\CustomerEmailController@addMailbox');
            $r->post('/email/domains/{id}/mailbox/{mid}/delete','Modules\Email\Controllers\CustomerEmailController@deleteMailbox');
            $r->post('/email/domains/{id}/mailbox/{mid}/password','Modules\Email\Controllers\CustomerEmailController@changePassword');
            $r->post('/email/domains/{id}/forwarder','Modules\Email\Controllers\CustomerEmailController@addForwarder');
            $r->post('/email/domains/{id}/catchall','Modules\Email\Controllers\CustomerEmailController@setCatchall');
            $r->post('/email/domains/{id}/forwarder/{fid}/delete','Modules\Email\Controllers\CustomerEmailController@deleteForwarder');
            $r->post('/email/domains/{id}/mailing-list','Modules\Email\Controllers\CustomerEmailController@addList');
            $r->post('/email/domains/{id}/mailing-list/{lid}/delete','Modules\Email\Controllers\CustomerEmailController@deleteList');
            $r->post('/email/domains/{id}/mailing-list/{lid}/posting','Modules\Email\Controllers\CustomerEmailController@setListPosting');
            $r->post('/email/domains/{id}/mailing-list/{lid}/member','Modules\Email\Controllers\CustomerEmailController@addListMember');
            $r->post('/email/domains/{id}/mailing-list/{lid}/member/{mid}/delete','Modules\Email\Controllers\CustomerEmailController@removeListMember');
            $r->get('/email/mailbox/{mid}/filters','Modules\Email\Controllers\CustomerEmailController@mailboxFilters');
            $r->post('/email/mailbox/{mid}/filters','Modules\Email\Controllers\CustomerEmailController@mailboxFiltersStore');
            $r->post('/email/mailbox/{mid}/filters/rules','Modules\Email\Controllers\CustomerEmailController@mailboxFilterAdd');
            $r->post('/email/mailbox/{mid}/filters/rules/{fid}/delete','Modules\Email\Controllers\CustomerEmailController@mailboxFilterDelete');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'email','label'=>'Email','icon'=>'mail','order'=>60,'children'=>[['id'=>'domains','label'=>'Mail Domains','url'=>'/admin/email','order'=>0],['id'=>'queue','label'=>'Mail Queue','url'=>'/admin/email/queue','order'=>10]]]],
            'customer'=>[['id'=>'email','label'=>'Email','icon'=>'mail','order'=>20,'children'=>[['id'=>'mailboxes','label'=>'Mailboxes','url'=>'/panel/email/mailboxes','order'=>0],['id'=>'forwarders','label'=>'Forwarders','url'=>'/panel/email/forwarders','order'=>10],['id'=>'catchall','label'=>'Catch-all','url'=>'/panel/email/catch-all','order'=>20],['id'=>'lists','label'=>'Mailing lists','url'=>'/panel/email/lists','order'=>30]]]],
        ];
    }
    public function migrations(): array { return [\Modules\Email\Migrations\CreateMailDomainsTable::class,\Modules\Email\Migrations\CreateMailboxesTable::class,\Modules\Email\Migrations\CreateForwardersTable::class,\Modules\Email\Migrations\AddRateLimitsAndCounters::class,\Modules\Email\Migrations\CreateMailAutorespondersTable::class,\Modules\Email\Migrations\CreateMailFiltersTable::class,\Modules\Email\Migrations\AddCatchallToMailDomains::class,\Modules\Email\Migrations\CreateMailingListsTable::class,\Modules\Email\Migrations\CreateMailingListMembersTable::class]; }
}