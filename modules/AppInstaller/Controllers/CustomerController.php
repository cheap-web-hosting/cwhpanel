<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\AppInstaller\Services\CatalogService;
use Modules\AppInstaller\Services\InstallerService;
use Modules\AppInstaller\Services\RequirementChecker;

class CustomerController
{
    private function accountId(): ?int
    {
        $user = app('auth')->user();
        if (!$user) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id` = ?', [$user->id]);
        return $row ? (int) $row['id'] : null;
    }

    /** Customer's domains (for choosing install location). */
    private function myDomains(): array
    {
        $aid = $this->accountId();
        return app('db')->select('SELECT * FROM `domains` WHERE `account_id` = ? AND `status` = "active" ORDER BY `name`', [$aid]);
    }

    public function index(Request $request): Response
    {
        $catalog = app(CatalogService::class);
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $tech = strtolower(trim((string) $request->query('tech', '')));
        if (!isset(CatalogService::techOptions()[$tech])) {
            $tech = 'all';
        }

        $apps = $category !== '' && $category !== 'all'
            ? $catalog->byCategory($category)
            : $catalog->allApplications();
        if ($q !== '') {
            $apps = array_values(array_filter($apps, fn ($a) => stripos($a['name'], $q) !== false || stripos((string)$a['description'], $q) !== false));
        }
        if ($tech !== 'all') {
            $apps = array_values(array_filter($apps, fn ($a) => CatalogService::techOf($catalog->manifest($a)) === $tech));
        }
        // A package that disables a runtime (max_*_apps = 0) hides its catalog
        // entries so customers never start an install that will be refused.
        $user = app('auth')->user();
        $accountId = $user !== null
            ? (int) app('db')->scalar('SELECT `id` FROM `accounts` WHERE `user_id` = ? LIMIT 1', [$user->id])
            : 0;
        $ls = \Modules\Packages\Services\LimitService::class;
        $off = [];
        foreach (['node', 'python'] as $rt) {
            if ($accountId > 0 && $ls::runtimeDisabled($accountId, $rt)) {
                $off[] = $rt;
            }
        }
        if ($off !== []) {
            $apps = array_values(array_filter(
                $apps,
                fn ($a) => !in_array(CatalogService::techOf($catalog->manifest($a)), $off, true)
            ));
        }
        foreach ($apps as &$a) {
            $a['display_version'] = $catalog->displayVersion($a);
            $a['tech'] = CatalogService::techOf($catalog->manifest($a));
        }
        unset($a);

        $content = view('customer/appinstaller/index', [
            'title' => 'App Installer',
            'apps' => $apps,
            'categories' => $catalog->categories(),
            'q' => $q,
            'activeCategory' => $category,
            'activeTech' => $tech,
            'techOptions' => CatalogService::techOptions(),
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function show(int $id): Response
    {
        $app = app(CatalogService::class)->findById($id);
        if (!$app) return Response::redirect('/panel/appinstaller');
        $manifest = app(CatalogService::class)->manifest($app);
        $content = view('customer/appinstaller/show', [
            'title' => $app['name'],
            'app' => $app,
            'manifest' => $manifest,
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function wizard(int $id): Response
    {
        $app = app(CatalogService::class)->findById($id);
        if (!$app) return Response::redirect('/panel/appinstaller');
        $manifest = app(CatalogService::class)->manifest($app);
        $checker = app(RequirementChecker::class);
        $requirements = $checker->check($manifest);
        $content = view('customer/appinstaller/wizard', [
            'title' => 'Install ' . $app['name'],
            'app' => $app,
            'manifest' => $manifest,
            'requirements' => $requirements,
            'domains' => $this->myDomains(),
            'old' => [],
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function start(int $id, Request $request): Response
    {
        $aid = $this->accountId();
        if (!$aid) return Response::redirect('/panel/appinstaller');

        $app = app(CatalogService::class)->findById($id);
        if (!$app || !(bool) $app['is_active']) return Response::redirect('/panel/appinstaller');

        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ? AND `account_id` = ?', [(int)$request->input('domain_id'), $aid]);
        if (!$domain) { app('session')->flash('error','Select a valid domain.'); return Response::redirect('/panel/appinstaller/install/'.$id); }

        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [$aid]);
        $docroot = (string) ($domain['document_root'] ?: '/home/'.$account['username'].'/public_html');
        $directory = trim((string) $request->input('directory', ''), '/');
        if (!preg_match('/^[a-zA-Z0-9_-]*$/', $directory)) { app('session')->flash('error','Invalid directory.'); return Response::redirect('/panel/appinstaller/install/'.$id); }

        // Requirement gate.
        $manifest = app(CatalogService::class)->manifest($app);
        $checker = app(RequirementChecker::class);
        $req = $checker->check($manifest);
        if (!$checker->allPass($req)) { app('session')->flash('error','This application cannot be installed: missing requirements.'); return Response::redirect('/panel/appinstaller/install/'.$id); }

        // App-type installs (Node.js/Python) mount on an existing website
        // of this account; the install directory doubles as the URL prefix.
        $websiteId = 0;
        if (app(\Modules\AppInstaller\Services\InstallerService::class)::isAppType($manifest)) {
            $website = app('db')->selectOne(
                'SELECT w.`id` FROM `websites` w JOIN `domains` d ON d.`id` = w.`domain_id`
                 WHERE d.`name` = ? AND d.`account_id` = ?',
                [$domain['name'], $aid]
            );
            if ($website === null) {
                app('session')->flash('error', 'Create a website for this domain first — the app mounts on it.');
                return Response::redirect('/panel/appinstaller/install/' . $id);
            }
            $websiteId = (int) $website['id'];
        }

        $opts = [
            'domain' => $domain['name'],
            'scheme' => 'https',
            'docroot' => $docroot,
            'directory' => $directory,
            'website_id' => $websiteId,
            'base_path' => $directory === '' ? '/' : '/' . $directory,
            'admin_user' => (string) $request->input('admin_user', 'admin'),
            'admin_password' => (string) $request->input('admin_password', bin2hex(random_bytes(8))),
            'admin_email' => (string) $request->input('admin_email', ''),
            'title' => (string) $request->input('title', $domain['name']),
        ];

        // WordPress/DokuWiki/Matomo and recipe-driven installs (setup.cli /
        // setup.sql manifests) finish unattended (setup runs as the account
        // via the agent), so the wizard values must be usable: a valid admin
        // email and a real password are required up front. Other apps keep
        // the old lenient behavior (their own web wizards finish setup).
        $template = (string) (($manifest['config'] ?? [])['template'] ?? '');
        $isAuto = in_array($template, ['wp', 'dokuwiki', 'matomo'], true)
            || isset($manifest['setup']['cli']) || isset($manifest['setup']['sql']) || isset($manifest['setup']['files'])
            || !empty($manifest['setup']['requires_creds']);
        // Credential-free setups (setup.no_creds) run without wizard
        // values: no username/password/email to validate or forward.
        $needsCreds = $isAuto && empty($manifest['setup']['no_creds']);
        if ($template === 'dokuwiki') {
            $opts['admin_user'] = strtolower(trim($opts['admin_user']));
        }
        if ($isAuto) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,32}$/', trim($opts['admin_user'])) !== 1) {
                app('session')->flash('error', 'Admin username: 1-32 chars, letters/numbers/._- only.');
                return Response::redirect('/panel/appinstaller/install/' . $id);
            }
            if (strlen($opts['admin_password']) < 8 || strlen($opts['admin_password']) > 256
                || str_contains($opts['admin_password'], "\n") || str_contains($opts['admin_password'], "\0")
            ) {
                app('session')->flash('error', 'Admin password: at least 8 characters (it signs you into the app).');
                return Response::redirect('/panel/appinstaller/install/' . $id);
            }
            if (strlen(trim($opts['admin_email'])) > 254
                || filter_var(trim($opts['admin_email']), FILTER_VALIDATE_EMAIL) === false
            ) {
                app('session')->flash('error', 'A valid admin email is required (app account + notifications).');
                return Response::redirect('/panel/appinstaller/install/' . $id);
            }
            $opts['admin_email'] = trim($opts['admin_email']);
            $opts['admin_user'] = trim($opts['admin_user']);
            $opts['title'] = trim($opts['title']) !== '' ? mb_substr(trim($opts['title']), 0, 200) : $domain['name'];
        }

        try {
            $installationId = app(InstallerService::class)->create($aid, $app, $opts);
            // Setup credentials ride the job payload (transient): the worker
            // consumes them for the unattended setup, and JobRunner scrubs
            // the password once the job reaches a terminal state.
            $jobPayload = ['installation_id' => $installationId];
            if ($needsCreds) {
                $jobPayload += [
                    'title' => $opts['title'],
                    'admin_user' => $opts['admin_user'],
                    'admin_password' => $opts['admin_password'],
                    'admin_email' => $opts['admin_email'],
                ];
            }
            $this->enqueue('appinstaller.install', $jobPayload);
            audit('appinstaller.installed', 'ai_installation', $installationId, ['app' => $app['slug']]);
            app('session')->flash('success', 'Installation queued. It will complete shortly.');
            return Response::redirect('/panel/appinstaller/installed/' . $installationId);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not start installation: ' . $e->getMessage());
            return Response::redirect('/panel/appinstaller/install/' . $id);
        }
    }

    public function installed(): Response
    {
        $aid = $this->accountId();
        $rows = app('db')->select('SELECT * FROM `ai_installations` WHERE `account_id` = ? ORDER BY `id` DESC', [$aid]);
        $content = view('customer/appinstaller/installed', ['title'=>'Installed Applications', 'rows'=>$rows], 'layouts/customer');
        return new Response((string) $content);
    }

    public function installedShow(int $id): Response
    {
        $aid = $this->accountId();
        $inst = app('db')->selectOne('SELECT * FROM `ai_installations` WHERE `id` = ? AND `account_id` = ?', [$id, $aid]);
        if (!$inst) return Response::redirect('/panel/appinstaller/installed');
        $logs = app('db')->select('SELECT * FROM `ai_install_logs` WHERE `installation_id` = ? ORDER BY `id`', [$id]);
        $content = view('customer/appinstaller/installedShow', ['title'=>'Installation details', 'inst'=>$inst, 'logs'=>$logs], 'layouts/customer');
        return new Response((string) $content);
    }

    public function uninstall(int $id, Request $request): Response
    {
        $aid = $this->accountId();
        $inst = app('db')->selectOne('SELECT * FROM `ai_installations` WHERE `id` = ? AND `account_id` = ?', [$id, $aid]);
        if (!$inst) return Response::redirect('/panel/appinstaller/installed');
        if (!in_array(($inst['status'] ?? ''), ['completed', 'failed'], true)) {
            app('session')->flash('error', 'Only completed or failed installations can be uninstalled.');
            return Response::redirect('/panel/appinstaller/installed/' . $id);
        }
        $removeFiles = $request->input('remove_files','1') === '1';
        $removeDb = $request->input('remove_db','1') === '1';
        $this->enqueue('appinstaller.uninstall', [
            'installation_id' => (int) $inst['id'],
            'remove_files' => $removeFiles,
            'remove_db' => $removeDb,
        ]);
        app('session')->flash('success', 'Uninstall queued.');
        return Response::redirect('/panel/appinstaller/installed');
    }

    public function update(int $id): Response
    {
        $aid = $this->accountId();
        $inst = app('db')->selectOne('SELECT * FROM `ai_installations` WHERE `id` = ? AND `account_id` = ?', [$id, $aid]);
        if (!$inst) return Response::redirect('/panel/appinstaller/installed');
        if (($inst['status'] ?? '') !== 'completed') {
            app('session')->flash('error', 'Only completed installations can be updated.');
            return Response::redirect('/panel/appinstaller/installed/' . $id);
        }
        $this->enqueue('appinstaller.update', ['installation_id' => (int) $inst['id']]);
        app('session')->flash('success', 'Update queued.');
        return Response::redirect('/panel/appinstaller/installed/'.$id);
    }

    private function enqueue(string $type, array $payload): void
    {
        app('db')->insert('jobs', [
            'type' => $type,
            'status' => 'pending',
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'result' => null,
            'attempts' => 0,
            'max_attempts' => 3,
            'available_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}