<?php
declare(strict_types=1);
namespace Modules\AppInstaller;
use App\Foundation\Router;
class Module extends \App\Foundation\Module\Module {
    public function id(): string { return 'appinstaller'; }
    public function name(): string { return 'Application Installer'; }
    public function version(): string { return '1.0.0'; }
    public function admin(): bool { return true; }
    public function customer(): bool { return true; }
    public function requires(): array { return ['core','accounts','domains','databases','jobs']; }
    public function permissions(): array {
        return ['appinstaller.view','appinstaller.install','appinstaller.manage','appinstaller.catalog','appinstaller.logs'];
    }
    public function routes(Router $router): void {
        $router->group(['prefix'=>'/admin/appinstaller','middleware'=>['auth.admin', 'can:appinstaller.view']], function(Router $r) {
            $r->get('/', 'Modules\AppInstaller\Controllers\AdminController@dashboard');
            $r->get('/applications', 'Modules\AppInstaller\Controllers\AdminController@applications');
            $r->get('/applications/create', 'Modules\AppInstaller\Controllers\AdminController@create');
            $r->post('/applications', 'Modules\AppInstaller\Controllers\AdminController@store');
            $r->get('/applications/{id}/edit', 'Modules\AppInstaller\Controllers\AdminController@edit');
            $r->post('/applications/{id}', 'Modules\AppInstaller\Controllers\AdminController@update');
            $r->post('/applications/{id}/delete', 'Modules\AppInstaller\Controllers\AdminController@destroy');
            $r->post('/applications/{id}/refresh', 'Modules\AppInstaller\Controllers\AdminController@refreshApp');
            $r->post('/applications/{id}/apply-update', 'Modules\AppInstaller\Controllers\AdminController@applyAppUpdate');
            $r->get('/categories', 'Modules\AppInstaller\Controllers\AdminController@categories');
            $r->post('/categories', 'Modules\AppInstaller\Controllers\AdminController@storeCategory');
            $r->post('/categories/{id}/delete', 'Modules\AppInstaller\Controllers\AdminController@deleteCategory');
            $r->get('/installations', 'Modules\AppInstaller\Controllers\AdminController@installations');
            $r->get('/installations/{id}', 'Modules\AppInstaller\Controllers\AdminController@installation');
        });
        $router->group(['prefix'=>'/panel/appinstaller','middleware'=>['auth.customer', 'feature.appinstaller']], function(Router $r) {
            $r->get('/', 'Modules\AppInstaller\Controllers\CustomerController@index');
            $r->get('/app/{id}', 'Modules\AppInstaller\Controllers\CustomerController@show');
            $r->get('/install/{id}', 'Modules\AppInstaller\Controllers\CustomerController@wizard');
            $r->post('/install/{id}', 'Modules\AppInstaller\Controllers\CustomerController@start');
            $r->get('/installed', 'Modules\AppInstaller\Controllers\CustomerController@installed');
            $r->get('/installed/{id}', 'Modules\AppInstaller\Controllers\CustomerController@installedShow');
            $r->post('/installed/{id}/uninstall', 'Modules\AppInstaller\Controllers\CustomerController@uninstall');
            $r->post('/installed/{id}/update', 'Modules\AppInstaller\Controllers\CustomerController@update');
        });
    }
    public function navigation(): array {
        return [
            'admin'=>[['id'=>'appinstaller','label'=>'App Installer','icon'=>'boxes','order'=>70,'children'=>[
                ['id'=>'apps','label'=>'Applications','url'=>'/admin/appinstaller/applications','order'=>0],
                ['id'=>'categories','label'=>'Categories','url'=>'/admin/appinstaller/categories','order'=>10],
                ['id'=>'installations','label'=>'Installations','url'=>'/admin/appinstaller/installations','order'=>20],
            ]]],
            'customer'=>[['id'=>'appinstaller','label'=>'App Installer','icon'=>'boxes','order'=>18,'children'=>[
                ['id'=>'all','label'=>'All Applications','url'=>'/panel/appinstaller','order'=>0],
                ['id'=>'installed','label'=>'Installed Applications','url'=>'/panel/appinstaller/installed','order'=>10],
            ]]],
        ];
    }
    public function migrations(): array {
        return [
            \Modules\AppInstaller\Migrations\CreateCategories::class,
            \Modules\AppInstaller\Migrations\CreateApplications::class,
            \Modules\AppInstaller\Migrations\CreateInstallations::class,
            \Modules\AppInstaller\Migrations\CreateInstallLogs::class,
            \Modules\AppInstaller\Migrations\AddApplicationIdToInstallations::class,
            \Modules\AppInstaller\Migrations\AddUpdateCheckToApplications::class,
            \Modules\AppInstaller\Migrations\UpdateMatomoDokuwikiAutoSetup::class,
            \Modules\AppInstaller\Migrations\UpdateNextcloudAutoSetup::class,
            \Modules\AppInstaller\Migrations\UpdateCliSetupApps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch1Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch2Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch3Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch4Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch5Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch6Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch7Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch8Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch9Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch10Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch11Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch12Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch13Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch14Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch15Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch16Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch17Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch18Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch19Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch20Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch21Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch22Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch23Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch24Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch25Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch26Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch27Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch29Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch30Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch31Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch32Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch33Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch34Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch35Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch36Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch37Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch38Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch41Apps::class,
            \Modules\AppInstaller\Migrations\UpdateBatch43Serve::class,
            \Modules\AppInstaller\Migrations\UpdateBatch43DocsifyVite::class,
            \Modules\AppInstaller\Migrations\UpdateBatch44PythonSweep::class,
            \Modules\AppInstaller\Migrations\UpdateBatch45BinarySweep::class,
            \Modules\AppInstaller\Migrations\UpdateBatch46Forge::class,
            \Modules\AppInstaller\Migrations\UpdateBatch47Freshrss::class,
            \Modules\AppInstaller\Migrations\UpdateFreshRssManifest::class,
            \Modules\AppInstaller\Migrations\UpdateWonderCmsSetup::class,
            \Modules\AppInstaller\Migrations\UpdateOpencartLycheeManifests::class,
            \Modules\AppInstaller\Migrations\UpdateBatch48AutoSetup::class,
            \Modules\AppInstaller\Migrations\UpdateBatch49AutoSetup::class,
            \Modules\AppInstaller\Migrations\UpdateBatch50AutoSetup::class,
            \Modules\AppInstaller\Migrations\RemoveUnautomatableApps::class,
            \Modules\AppInstaller\Migrations\RemoveMicroweberApp::class,
            \Modules\AppInstaller\Migrations\RemoveDeadApps::class,
        ];
    }

    /**
     * Seed default categories and the WordPress reference application (idempotent).
     */
    public function boot(): void
    {
        // Skip on pre-migration databases (clean installs): the tables do
        // not exist yet and seeding happens on the first post-migrate boot.
        if (!$this->tablesExist(['ai_categories', 'ai_applications'])) {
            return;
        }
        $db = app('db');

        // Default categories.
        $cats = [
            'Blogs', 'CMS', 'E-commerce', 'Forums', 'Wikis', 'CRM', 'Marketing',
            'Analytics', 'Project Management', 'Developer Tools', 'Frameworks',
            'Education', 'Media', 'Social', 'Utilities', 'Portals',
            'File Sharing', 'Monitoring', 'Documentation', 'Finance',
        ];
        foreach (array_values($cats) as $i => $name) {
            $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($name));
            $existing = $db->selectOne('SELECT `id` FROM `ai_categories` WHERE `slug` = ?', [$slug]);
            if (!$existing) {
                $db->insert('ai_categories', [
                    'name' => $name, 'slug' => $slug, 'sort_order' => $i, 'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Catalog seed data lives in Seed\CatalogApps (92 applications).
        // Rows are insert-if-missing: existing entries (and any admin edits)
        // are never touched, so reseeds are safe. Fresh rows with a live
        // update_check get their latest_version resolved immediately — an
        // unchecked row displays the bare word 'latest' on cards until the
        // weekly sweep first runs (the exact bug reported on Nextcloud et
        // al). Failures are swallowed: the sweep fills them in later.
        $apps = \Modules\AppInstaller\Seed\CatalogApps::all();
        foreach ($apps as $a) {
            if ($db->selectOne('SELECT `id` FROM `ai_applications` WHERE `slug` = ?', [$a['slug']])) {
                continue;
            }
            $manifest = $a['manifest'];
            unset($a['manifest']);
            $featured = (int) ($a['featured'] ?? 0);
            $sort = (int) ($a['sort'] ?? 0);
            unset($a['featured'], $a['sort']);
            $id = (int) $db->insert('ai_applications', $a + [
                'manifest' => json_encode($manifest),
                'is_active' => 1,
                'is_featured' => $featured,
                'sort_order' => $sort,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $uc = $manifest['update_check'] ?? null;
            if (is_array($uc) && !empty($uc['type']) && ($uc['type'] ?? '') !== 'static') {
                try {
                    app(\Modules\AppInstaller\Services\CatalogUpdateService::class)->refreshOne($id);
                } catch (\Throwable $e) {
                }
            }
        }
        // Converge deletions too: rows whose slug is no longer seeded
        // (removed apps) are deleted, so a stale-code boot (opcache serves
        // the previous CatalogApps until php-fpm reloads — bitten live:
        // Arastta/Grocy re-appeared seconds after their removal migration)
        // can never resurrect them. All catalog rows are seed-defined, so
        // an unknown slug is always debris.
        try {
            $slugs = [];
            foreach ($apps as $a) {
                $slugs[] = (string) ($a['slug'] ?? '');
            }
            $slugs = array_values(array_filter($slugs));
            if ($slugs !== []) {
                $ph = implode(',', array_fill(0, count($slugs), '?'));
                $db->delete('ai_applications', "`slug` NOT IN ({$ph})", $slugs);
            }
        } catch (\Throwable $e) {
        }
    }
}