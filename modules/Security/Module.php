<?php
declare(strict_types=1);
namespace Modules\Security;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'security'; }
    public function name(): string { return 'Security'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core']; }
    public function permissions(): array { return ['security.view','security.manage']; }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin','middleware'=>['auth.admin', 'can:security.view']], function(Router $r) {
            $r->get('/security','Modules\Security\Controllers\SecurityController@index');
            $r->get('/security/firewall','Modules\Security\Controllers\SecurityController@firewall');
            $r->post('/security/firewall/block','Modules\Security\Controllers\SecurityController@block')->withMiddleware('stepup');
            $r->post('/security/firewall/unblock/{id}','Modules\Security\Controllers\SecurityController@unblock')->withMiddleware('stepup');
            $r->post('/security/firewall/unblock-site/{id}','Modules\Security\Controllers\SecurityController@unblockSiteDeny')->withMiddleware('stepup');
            $r->post('/security/firewall/open-port','Modules\Security\Controllers\SecurityController@openPort')->withMiddleware('stepup');
            $r->post('/security/firewall/close-port','Modules\Security\Controllers\SecurityController@closePort')->withMiddleware('stepup');
            $r->post('/security/firewall/mysql-remote','Modules\Security\Controllers\SecurityController@mysqlRemote')->withMiddleware('stepup');
            $r->get('/security/audit','Modules\Security\Controllers\SecurityController@audit');
            $r->get('/security/waf','Modules\Security\Controllers\SecurityController@adminWaf');
            $r->post('/security/waf/toggle','Modules\Security\Controllers\SecurityController@adminWafToggle')->withMiddleware('stepup');
            $r->post('/security/waf/exclusions','Modules\Security\Controllers\SecurityController@adminExclusionAdd')->withMiddleware('stepup');
            $r->post('/security/waf/exclusions/delete','Modules\Security\Controllers\SecurityController@adminExclusionRemove')->withMiddleware('stepup');
            $r->post('/security/hotlink/toggle','Modules\Security\Controllers\SecurityController@adminHotlinkToggle')->withMiddleware('stepup');
            $r->get('/security/antivirus','Modules\Security\Controllers\SecurityController@adminAntivirus');
            $r->post('/security/antivirus/scan','Modules\Security\Controllers\SecurityController@adminAntivirusScan');
            $r->post('/security/antivirus/scan-all','Modules\Security\Controllers\SecurityController@adminAntivirusScanAll');
        });
        $router->group(['prefix'=>'/panel','middleware'=>['auth.customer', 'feature.security']], function(Router $r) {
            $r->get('/security','Modules\Security\Controllers\SecurityController@customerIndex');
            $r->post('/security/ip-block','Modules\Security\Controllers\SecurityController@customerBlockIp');
            $r->post('/security/ip-block/{id}/remove','Modules\Security\Controllers\SecurityController@customerUnblockIp');
            $r->get('/security/waf','Modules\Security\Controllers\SecurityController@customerWaf');
            $r->post('/security/waf/toggle','Modules\Security\Controllers\SecurityController@customerWafToggle');
            $r->post('/security/waf/exclusions','Modules\Security\Controllers\SecurityController@customerExclusionAdd');
            $r->post('/security/waf/exclusions/delete','Modules\Security\Controllers\SecurityController@customerExclusionRemove');
            $r->post('/security/hotlink/toggle','Modules\Security\Controllers\SecurityController@customerHotlinkToggle');
            $r->post('/security/antivirus/scan','Modules\Security\Controllers\SecurityController@customerAntivirusScan');
            $r->get('/security/antivirus','Modules\Security\Controllers\SecurityController@customerAntivirus');
            $r->get('/security/ssh','Modules\Security\Controllers\SecurityController@customerSsh');
            $r->post('/security/ssh/keys','Modules\Security\Controllers\SecurityController@customerSshAddKey');
            $r->post('/security/ssh/keys/{index}/delete','Modules\Security\Controllers\SecurityController@customerSshRemoveKey');
            $r->post('/security/ssh/shell','Modules\Security\Controllers\SecurityController@customerSshShell');
            $r->post('/security/ssh/password','Modules\Security\Controllers\SecurityController@customerSshPassword');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'security','label'=>'Security','icon'=>'shield-check','order'=>100,'children'=>[['id'=>'overview','label'=>'Overview','url'=>'/admin/security','order'=>0],['id'=>'firewall','label'=>'Firewall','url'=>'/admin/security/firewall','order'=>10],['id'=>'waf','label'=>'Web Application Firewall','url'=>'/admin/security/waf','order'=>20],['id'=>'antivirus','label'=>'Antivirus','url'=>'/admin/security/antivirus','order'=>30],['id'=>'audit','label'=>'Audit Log','url'=>'/admin/security/audit','order'=>40]]]],
            'customer'=>[['id'=>'security','label'=>'Security','icon'=>'shield-check','order'=>38,'children'=>[['id'=>'events','label'=>'Security','url'=>'/panel/security','order'=>0],['id'=>'waf','label'=>'WAF','url'=>'/panel/security/waf','order'=>10],['id'=>'antivirus','label'=>'Antivirus','url'=>'/panel/security/antivirus','order'=>20],['id'=>'ssh','label'=>'SSH Access','url'=>'/panel/security/ssh','order'=>30]]]],
        ];
    }
    public function migrations(): array { return [\Modules\Security\Migrations\CreateFirewallRulesTable::class, \Modules\Security\Migrations\AddOwnerToFirewallRules::class, \Modules\Security\Migrations\CreateSecuritySettingsTable::class, \Modules\Security\Migrations\CreateSecurityScansTable::class, \Modules\Security\Migrations\CreateFirewallAttacksTable::class, \Modules\Security\Migrations\CreateWafExclusionsTable::class, \Modules\Security\Migrations\CreateWebsiteIpDenies::class]; }
}