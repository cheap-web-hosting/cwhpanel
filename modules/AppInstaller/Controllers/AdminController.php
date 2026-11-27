<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\AppInstaller\Services\CatalogService;

class AdminController
{
    public function dashboard(): Response
    {
        $apps = (int) app('db')->scalar('SELECT COUNT(*) FROM `ai_applications`');
        $enabled = (int) app('db')->scalar('SELECT COUNT(*) FROM `ai_applications` WHERE `is_active` = 1');
        $installs = (int) app('db')->scalar('SELECT COUNT(*) FROM `ai_installations`');
        $active = (int) app('db')->scalar("SELECT COUNT(*) FROM `ai_installations` WHERE `status` = 'completed'");
        $failed = (int) app('db')->scalar("SELECT COUNT(*) FROM `ai_installations` WHERE `status` = 'failed'");
        $content = view('admin/appinstaller/dashboard', [
            'title' => 'Application Installer',
            'apps' => $apps, 'enabled' => $enabled, 'installs' => $installs, 'active' => $active, 'failed' => $failed,
        ], 'layouts/admin');
        return new Response((string) $content);
    }

    public function applications(): Response
    {
        $apps = app('db')->select('SELECT * FROM `ai_applications` ORDER BY `name`');
        foreach ($apps as &$a) {
            $a['update_available'] = \Modules\AppInstaller\Services\CatalogUpdateService::updateAvailable($a);
            $a['display_version'] = app(\Modules\AppInstaller\Services\CatalogService::class)->displayVersion($a);
        }
        unset($a);
        $content = view('admin/appinstaller/applications', ['title'=>'Applications', 'apps'=>$apps], 'layouts/admin');
        return new Response((string)$content);
    }

    /** Refresh one catalog row against upstream now. */
    public function refreshApp(int $id): Response
    {
        try {
            $res = app(\Modules\AppInstaller\Services\CatalogUpdateService::class)->refreshOne($id);
            app('session')->flash(
                $res['checked'] ? 'success' : 'error',
                $res['checked'] ? ('Upstream: ' . $res['latest']) : ('Refresh failed: ' . $res['error'])
            );
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/appinstaller/applications');
    }

    /** Copy latest_version into version (admin accepts the update). */
    public function applyAppUpdate(int $id): Response
    {
        try {
            app(\Modules\AppInstaller\Services\CatalogUpdateService::class)->applyUpdate($id);
            app('session')->flash('success', 'Catalog entry updated to the upstream version.');
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/appinstaller/applications');
    }

    public function create(): Response
    {
        $categories = app('db')->select('SELECT * FROM `ai_categories` ORDER BY `name`');
        $content = view('admin/appinstaller/form', ['title'=>'Add Application', 'app'=>null, 'old'=>[], 'categories'=>$categories], 'layouts/admin');
        return new Response((string) $content);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateApp($request);
        if (!$data) return Response::redirect('/admin/appinstaller/applications/create');
        $id = app('db')->insert('ai_applications', $data);
        audit('appinstaller.app.created', 'ai_application', $id, ['name'=>$data['name']]);
        app('session')->flash('success', 'Application added.');
        return Response::redirect('/admin/appinstaller/applications');
    }

    public function edit(int $id): Response
    {
        $app = app('db')->selectOne('SELECT * FROM `ai_applications` WHERE `id` = ?', [$id]);
        if (!$app) return Response::redirect('/admin/appinstaller/applications');
        $categories = app('db')->select('SELECT * FROM `ai_categories` ORDER BY `name`');
        $old = json_decode((string)$app['manifest'], true) ?: [];
        $content = view('admin/appinstaller/form', ['title'=>'Edit Application', 'app'=>$app, 'old'=>$old, 'categories'=>$categories], 'layouts/admin');
        return new Response((string) $content);
    }

    public function update(int $id, Request $request): Response
    {
        $app = app('db')->selectOne('SELECT * FROM `ai_applications` WHERE `id` = ?', [$id]);
        if (!$app) return Response::redirect('/admin/appinstaller/applications');
        // Merge over the stored manifest so fields the form does not
        // represent (github/codeberg blocks, file/pip/npm/app/update_check
        // sections) survive an edit instead of being wiped.
        $base = json_decode((string)($app['manifest'] ?? '{}'), true) ?: [];
        $data = $this->validateApp($request, $base);
        if (!$data) return Response::redirect('/admin/appinstaller/applications/'.$id.'/edit');
        app('db')->update('ai_applications', $data, '`id` = ?', [$id]);
        audit('appinstaller.app.updated', 'ai_application', $id);
        app('session')->flash('success', 'Application updated.');
        return Response::redirect('/admin/appinstaller/applications');
    }

    public function destroy(int $id): Response
    {
        app('db')->delete('ai_applications', '`id` = ?', [$id]);
        audit('appinstaller.app.deleted', 'ai_application', $id);
        app('session')->flash('success', 'Application deleted.');
        return Response::redirect('/admin/appinstaller/applications');
    }

    public function categories(): Response
    {
        $categories = app('db')->select('SELECT * FROM `ai_categories` ORDER BY `sort_order`, `name`');
        $content = view('admin/appinstaller/categories', ['title'=>'Categories', 'categories'=>$categories], 'layouts/admin');
        return new Response((string) $content);
    }

    public function storeCategory(Request $request): Response
    {
        try { $d = $request->validate(['name'=>'required|max:100']); }
        catch (ValidationException $e) { app('session')->flash('error','Name required.'); return Response::redirect('/admin/appinstaller/categories'); }
        $name = trim((string)$d['name']);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($name));
        $slug = trim($slug, '-') ?: 'cat';
        app('db')->insert('ai_categories', [
            'name'=>$name, 'slug'=>$slug, 'sort_order'=>(int)$request->input('sort_order',0),
            'is_active'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s'),
        ]);
        app('session')->flash('success', 'Category added.');
        return Response::redirect('/admin/appinstaller/categories');
    }

    public function deleteCategory(int $id): Response
    {
        app('db')->delete('ai_categories', '`id` = ?', [$id]);
        app('session')->flash('success', 'Category deleted.');
        return Response::redirect('/admin/appinstaller/categories');
    }

    public function installations(): Response
    {
        $rows = app('db')->select('SELECT i.*, a.`username` FROM `ai_installations` i LEFT JOIN `accounts` a ON a.`id`=i.`account_id` ORDER BY i.`id` DESC LIMIT 200');
        $content = view('admin/appinstaller/installations', ['title'=>'Installations', 'rows'=>$rows], 'layouts/admin');
        return new Response((string) $content);
    }

    public function installation(int $id): Response
    {
        $inst = app('db')->selectOne('SELECT i.*, a.`username` FROM `ai_installations` i LEFT JOIN `accounts` a ON a.`id`=i.`account_id` WHERE i.`id`=?', [$id]);
        if (!$inst) return Response::redirect('/admin/appinstaller/installations');
        $logs = app('db')->select('SELECT * FROM `ai_install_logs` WHERE `installation_id`=? ORDER BY `id`', [$id]);
        $content = view('admin/appinstaller/installation', ['title'=>'Installation', 'inst'=>$inst, 'logs'=>$logs], 'layouts/admin');
        return new Response((string) $content);
    }

    private function validateApp(Request $request, ?array $base = null): ?array
    {
        try {
            $d = $request->validate(['name'=>'required|max:191','slug'=>'required|max:120']);
        } catch (ValidationException $e) {
            app('session')->flash('error','Name and slug are required.');
            app('session')->flashInput($request->all());
            return null;
        }
        $base = $base ?? [];
        $baseReq = is_array($base['requirements'] ?? null) ? $base['requirements'] : [];
        $baseDb = is_array($base['database'] ?? null) ? $base['database'] : [];
        $baseDl = is_array($base['download'] ?? null) ? $base['download'] : [];
        $baseCfg = is_array($base['config'] ?? null) ? $base['config'] : [];
        $baseAdmin = is_array($base['admin'] ?? null) ? $base['admin'] : [];
        $manifest = $base;
        $manifest['type'] = in_array($request->input('app_type', 'php-mysql'), ['php-mysql', 'php', 'python-pip', 'node-npm', 'node-archive', 'binary'], true)
            ? (string) $request->input('app_type') : 'php-mysql';
        $manifest['requirements'] = array_merge($baseReq, [
            'php_min' => (string)$request->input('php_min','7.4'),
            'extensions' => array_values(array_filter(array_map('trim', explode(',', (string)$request->input('extensions',''))))),
        ]);
        $manifest['database'] = array_merge($baseDb, [
            'required' => $request->input('db_required','1') === '1',
            'prefix' => (string)$request->input('db_prefix','wp_'),
        ]);
        $download = $baseDl;
        // An explicit URL overrides a github/codeberg-templated source;
        // an empty field leaves the stored source block untouched.
        if (trim((string)$request->input('download_url','')) !== '') {
            $download['url'] = (string)$request->input('download_url','');
            unset($download['github'], $download['codeberg']);
        }
        $download['sha256'] = (string)$request->input('sha256','');
        $download['format'] = in_array($request->input('archive_format','zip'), ['zip','tar','file'], true)
            ? (string)$request->input('archive_format') : 'zip';
        $download['strip_root'] = $request->input('strip_root','1') === '1';
        $manifest['download'] = $download;
        $manifest['config'] = array_merge($baseCfg, [
            'template' => (string)$request->input('config_template','wp'),
            'file' => (string)$request->input('config_file','wp-config.php'),
            'prefix' => (string)$request->input('db_prefix','wp_'),
        ]);
        $manifest['admin'] = array_merge($baseAdmin, [
            'path' => (string)$request->input('admin_path','wp-admin/'),
        ]);
        // Advanced JSON merges over the field-built manifest (pip/npm/app/
        // update_check sections for non-PHP types). Must decode to an array.
        $extra = trim((string) $request->input('manifest_json', ''));
        if ($extra !== '') {
            $decoded = json_decode($extra, true);
            if (!is_array($decoded)) {
                app('session')->flash('error', 'Advanced manifest JSON is invalid.');
                app('session')->flashInput($request->all());
                return null;
            }
            $manifest = array_replace_recursive($manifest, $decoded);
        }
        return [
            'name' => (string)$d['name'],
            'slug' => $this->slugify((string)$d['slug']),
            'category' => (string)$request->input('category','CMS'),
            'version' => (string)$request->input('version','1.0'),
            'description' => (string)$request->input('description',''),
            'website' => (string)$request->input('website',''),
            'license' => (string)$request->input('license','GPL-2.0'),
            'vendor' => (string)$request->input('vendor',''),
            'manifest' => json_encode($manifest),
            'is_active' => $request->input('is_active','1') === '1' ? 1 : 0,
            'is_featured' => $request->input('is_featured','0') === '1' ? 1 : 0,
            'sort_order' => (int)$request->input('sort_order',0),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function slugify(string $s): string
    {
        $s = preg_replace('/[^a-z0-9-]+/', '-', strtolower($s));
        return trim($s, '-') ?: 'app';
    }
}