<?php

declare(strict_types=1);

namespace Modules\Applications\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Applications\Services\AppService;

/**
 * Shared logic for the admin/customer runtime-app controllers (Node.js /
 * Python). The two panels mirror the same pages, actions and AppService
 * flows; they differ only in account scoping, the asAdmin flag on
 * provisioning calls, form chrome (admin picks the account) and redirect
 * prefixes. Everything identical lives here so fixes land once.
 */
abstract class ApplicationControllerBase
{
    protected AppService $svc;

    public function __construct(AppService $svc)
    {
        $this->svc = $svc;
    }

    /** '/panel' or '/admin' — every redirect derives from this. */
    abstract protected function areaPrefix(): string;

    /** 'layouts/customer' or 'layouts/admin'. */
    abstract protected function layout(): string;

    /** 'customer/...' or 'admin/...' prefix for views. */
    abstract protected function viewArea(string $template): string;

    /** True when acting as admin (passes asAdmin=true to AppService calls). */
    abstract protected function asAdmin(): bool;

    /** List rows for the index page (customer: own account; admin: all + search). */
    abstract protected function indexRows(string $runtime, string $search): array;

    /** Extra index view data (admin: search term). */
    abstract protected function indexExtraViewData(string $search): array;

    /** Account choices for the create form (admin: active accounts; customer: none). */
    abstract protected function createAccounts(): array;

    /**
     * Resolve + validate the account a new app belongs to.
     *
     * @return array{0:?array,1:?Response} [account, errorRedirect]
     */
    abstract protected function createAccount(array $data, string $type, ?array $parent): array;

    /** Resolve the account row that owns an app (customer: session account). */
    abstract protected function accountForApp(array $app): ?array;

    /** Find an app row within this area's scope. */
    abstract protected function findApp(int $id): ?array;

    /** Audit a control action (start/stop/restart) — admin audits, customer doesn't. */
    abstract protected function auditControl(string $action, int $id): void;

    private function meta(string $runtime): array
    {
        $labels = ['node' => 'Node.js', 'python' => 'Python'];
        $bases = ['node' => '{area}/nodejs', 'python' => '{area}/python'];
        if (!isset($labels[$runtime])) {
            throw new \RuntimeException('Unknown runtime.');
        }
        return [
            'label' => $labels[$runtime],
            'base' => str_replace('{area}', $this->areaPrefix(), $bases[$runtime]),
        ];
    }

    private function baseForApp(array $app): string
    {
        $rt = ($app['runtime'] ?? '') === 'python' ? 'python' : 'node';
        return $this->meta($rt)['base'];
    }

    // ------------------------------------------------------------ shared bodies

    protected function indexFor(string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $search = trim((string) (app('request')->query('q', '')));
        $apps = $this->indexRows($runtime, $search);
        $data = array_merge([
            'title' => $meta['label'] . ' Apps',
            'apps' => $apps,
            'runtime' => $runtime,
            'base' => $meta['base'],
            'label' => $meta['label'],
            'prefix' => $meta['base'],
            'disabledRuntime' => $this->runtimeDisabledForCurrentUser($runtime),
        ], $this->indexExtraViewData($search));
        $content = view($this->viewArea('applications/index'), $data, $this->layout());
        return new Response((string) $content);
    }

    /** Package-level Node.js/Python disable (max_*_apps = 0), customer scope. */
    protected function runtimeDisabledForCurrentUser(string $runtime): bool
    {
        if ($this->asAdmin()) {
            return false;
        }
        $user = app('auth')->user();
        if ($user === null) {
            return false;
        }
        $accountId = (int) app('db')->scalar('SELECT `id` FROM `accounts` WHERE `user_id` = ? LIMIT 1', [$user->id]);
        return $accountId > 0
            && \Modules\Packages\Services\LimitService::runtimeDisabled($accountId, $runtime);
    }

    protected function createFor(string $runtime): Response
    {
        $meta = $this->meta($runtime);
        if ($this->runtimeDisabledForCurrentUser($runtime)) {
            app('session')->flash('error', $meta['label'] . ' apps are disabled for your package.');
            return Response::redirect($meta['base']);
        }
        $data = [
            'title' => 'Add ' . $meta['label'] . ' App',
            'old' => [],
            'runtime' => $runtime,
            'base' => $meta['base'],
        ];
        $accounts = $this->createAccounts();
        if ($accounts !== []) {
            $data['accounts'] = $accounts;
        }
        $content = view($this->viewArea('applications/form'), $data, $this->layout());
        return new Response((string) $content);
    }

    protected function storeFor(Request $request, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $rules = [
            'name' => 'required|alpha_dash|max:64',
            'entry_point' => 'required|max:255',
            'git_url' => 'max:255',
            'git_branch' => 'max:64',
        ];
        if ($this->asAdmin()) {
            $rules = ['account_id' => 'required|integer'] + $rules;
        }
        try {
            $data = $request->validate($rules);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, $meta['base'] . '/create');
        }

        $sourceError = AppService::validateSource($runtime, (string) $data['entry_point'], $data['git_url'] ?? null, $data['git_branch'] ?? 'main');
        if ($sourceError !== null) {
            app('session')->flash('error', $sourceError);
            return Response::redirect($meta['base'] . '/create');
        }

        [$account, $deny] = $this->createAccount($data, $runtime, null);
        if ($deny !== null) {
            return $deny;
        }
        if ($account === null) {
            app('session')->flash('error', 'Invalid account.');
            return Response::redirect($meta['base'] . '/create');
        }

        try {
            \Modules\Packages\Services\LimitService::require((int) $account['id'], $runtime);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect($meta['base'] . '/create');
        }

        $workDir = $this->svc->createWorkDir($account['username'], $data['name'], $this->asAdmin());
        $runtimeVersion = AppService::normalizeVersion($runtime, (string) $request->input('runtime_version', ''));
        $id = app('db')->insert('applications', [
            'account_id' => (int) $account['id'],
            'name' => $data['name'],
            'runtime' => $runtime,
            'runtime_version' => $runtimeVersion,
            'entry_point' => $data['entry_point'],
            'work_dir' => $workDir,
            'status' => 'stopped',
            'git_url' => $data['git_url'] ?? null,
            'git_branch' => $data['git_branch'] ?? 'main',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        audit('app.created', 'application', $id, ['name' => $data['name']]);
        app('session')->flash('success', 'Application created.');
        return Response::redirect($meta['base'] . '/' . $id);
    }

    protected function showFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->findApp($id);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        if (($app['runtime'] ?? '') !== $runtime) {
            // Cross-linked from the other runtime page: bounce to the right one.
            return Response::redirect($this->baseForApp($app) . '/' . $id);
        }
        $venv = null;
        $packages = [];
        if ($runtime === 'python') {
            $account = $this->accountForApp($app);
            if ($account) {
                try {
                    $venv = app(AppService::class)->hasVenv($account, $app);
                } catch (\Throwable $e) {
                    $venv = null;
                }
                try {
                    $packages = app(AppService::class)->installedPackages($account, $app);
                } catch (\Throwable $e) {
                    $packages = [];
                }
            }
        }
        $content = view($this->viewArea('applications/show'), [
            'title' => 'App: ' . $app['name'],
            'app' => $app,
            'venv' => $venv,
            'base' => $meta['base'],
            'packages' => $packages,
            'packageCatalog' => AppService::PYTHON_PACKAGES,
            'webhook_url' => AppService::webhookUrl((int) $app['id']),
            'appEnv' => AppService::parseEnv($app['env'] ?? null),
            'parentApp' => $this->parentFor($app),
        ], $this->layout());
        return new Response((string) $content);
    }

    protected function destroyFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->findApp($id);
        if (!$app || ($app['runtime'] ?? '') !== $runtime) {
            return Response::redirect($meta['base']);
        }
        $account = $this->accountForApp($app);
        $stopError = null;
        if ($account) {
            // Never orphan a running unit: stop first (best-effort), then
            // delete the row. Work files stay on disk for file-manager cleanup.
            try {
                app(AppService::class)->stop($account, $app);
            } catch (\Throwable $e) {
                app('logger')->warning('App stop failed on delete: ' . $e->getMessage());
                $stopError = $e->getMessage();
            }
        }
        app('db')->delete('applications', '`id`=?', [$id]);
        // Release the per-account app network namespace when the last app is
        // gone: it (and its veth) otherwise stays mounted until reboot.
        if ($account) {
            try {
                $left = app('db')->selectOne(
                    'SELECT COUNT(*) AS n FROM `applications` WHERE `account_id` = ?',
                    [(int) $account['id']]
                );
                if ((int) ($left['n'] ?? 0) === 0) {
                    app(\Modules\Network\Services\AppNetnsService::class)->destroy($account);
                }
            } catch (\Throwable $e) {
                app('logger')->warning('App netns release failed: ' . $e->getMessage());
            }
        }
        audit('app.deleted', 'application', $id);
        $msg = 'Application deleted.';
        if ($stopError !== null) {
            $msg .= ' Warning: the running process could not be stopped (agent error: ' . $stopError . ') — check Application processes.';
        }
        app('session')->flash('success', $msg);
        return Response::redirect($meta['base']);
    }

    protected function startFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        $account = $this->accountForApp($app);
        $startError = null;
        try {
            $ok = $account ? app(AppService::class)->start($account, $app, $this->asAdmin()) : false;
        } catch (\Throwable $e) {
            $ok = false;
            $startError = $e->getMessage();
        }
        app('db')->update('applications', ['status' => $ok ? 'running' : 'stopped', 'updated_at' => date('Y-m-d H:i:s')], '`id`=?', [$id]);
        if ($this->asAdmin()) {
            $this->auditControl($ok ? 'app.started' : 'app.start.failed', $id);
        }
        app('session')->flash(
            $ok ? 'success' : 'error',
            $ok ? 'Application started.' : 'Application failed to start' . ($startError ? ': ' . $startError : '.')
        );
        return Response::redirect($meta['base'] . '/' . $id);
    }

    protected function stopFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        $account = $this->accountForApp($app);
        if ($account) {
            app(AppService::class)->stop($account, $app);
        }
        app('db')->update('applications', ['status' => 'stopped', 'updated_at' => date('Y-m-d H:i:s')], '`id`=?', [$id]);
        if ($this->asAdmin()) {
            $this->auditControl('app.stopped', $id);
        }
        app('session')->flash('success', 'Application stopped.');
        return Response::redirect($meta['base'] . '/' . $id);
    }

    protected function restartFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        $account = $this->accountForApp($app);
        $ok = false;
        $startError = null;
        if ($account) {
            try {
                app(AppService::class)->stop($account, $app);
            } catch (\Throwable $e) {
                app('logger')->warning('App stop before restart failed: ' . $e->getMessage());
            }
            usleep(300000);
            try {
                $ok = app(AppService::class)->start($account, $app, $this->asAdmin());
            } catch (\Throwable $e) {
                $ok = false;
                $startError = $e->getMessage();
            }
        }
        app('db')->update('applications', ['status' => $ok ? 'running' : 'stopped', 'updated_at' => date('Y-m-d H:i:s')], '`id`=?', [$id]);
        if ($this->asAdmin()) {
            $this->auditControl('app.restarted', $id);
        }
        app('session')->flash(
            $ok ? 'success' : 'error',
            $ok ? 'Application restarted.' : 'Application failed to restart' . ($startError ? ': ' . $startError : '.')
        );
        return Response::redirect($meta['base'] . '/' . $id);
    }

    protected function deployFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        $account = $this->accountForApp($app);
        $ok = $account ? app(AppService::class)->deploy($account, $app, $this->asAdmin()) : false;
        audit('app.deployed', 'application', $id, ['ok' => $ok]);
        app('session')->flash($ok ? 'success' : 'error', $ok ? 'Deployment complete.' : 'Deployment failed.');
        return Response::redirect($meta['base'] . '/' . $id);
    }

    /**
     * Toggle the push-to-deploy webhook. Enabling without a secret mints
     * one (the forge needs something to sign with); disabling answers 404
     * on the receiver without revealing the app exists.
     */
    protected function webhookFor(int $id, Request $request, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        $enabled = (bool) $request->input('enabled');
        $secret = (string) ($app['webhook_secret'] ?? '');
        if ($enabled && $secret === '') {
            app(AppService::class)->rotateWebhookSecret($id);
        }
        app('db')->update('applications', ['webhook_enabled' => $enabled ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], '`id`=?', [$id]);
        audit($enabled ? 'app.webhook.enabled' : 'app.webhook.disabled', 'application', $id);
        app('session')->flash('success', $enabled ? 'Webhook enabled. Configure the forge with the URL + secret below.' : 'Webhook disabled.');
        return Response::redirect($meta['base'] . '/' . $id);
    }

    /** Replace the webhook secret (old deliveries stop verifying). */
    protected function webhookRotateFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        app(AppService::class)->rotateWebhookSecret($id);
        audit('app.webhook.rotated', 'application', $id);
        app('session')->flash('success', 'New webhook secret generated — update the forge settings.');
        return Response::redirect($meta['base'] . '/' . $id);
    }

    /** Replace the app's env vars from the textarea (restart to apply). */
    protected function envSaveFor(int $id, Request $request, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        $account = $this->accountForApp($app);
        try {
            if (!$account) {
                throw new \RuntimeException('Application has no account.');
            }
            app(AppService::class)->saveEnvLines($account, $app, (string) $request->input('env', ''), $this->asAdmin());
            app('session')->flash('success', 'Environment saved — restart the app to apply.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Environment not saved: ' . $e->getMessage());
        }
        return Response::redirect($meta['base'] . '/' . $id);
    }

    /** Queue a background update (git pull + dependency refresh + restart). */
    protected function updateFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        try {
            app('db')->insert('jobs', [
                'type' => 'app.update',
                'status' => 'pending',
                'payload' => json_encode(['application_id' => $id]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 1,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('app.update.queued', 'application', $id);
            app('session')->flash('success', 'Update queued — it pulls git, refreshes dependencies, and restarts a running app.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue update: ' . $e->getMessage());
        }
        return Response::redirect($meta['base'] . '/' . $id);
    }

    /** Clone the app as staging (files + env, fresh port, stopped). */
    protected function cloneFor(int $id, Request $request, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        $account = $this->accountForApp($app);
        try {
            if (!$account) {
                throw new \RuntimeException('Application has no account.');
            }
            $newId = app(AppService::class)->cloneApp($account, $app, (string) $request->input('name', ''), $this->asAdmin());
            app('session')->flash('success', 'Staging clone created (stopped, no proxy mount).');
            return Response::redirect($meta['base'] . '/' . $newId);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Clone failed: ' . $e->getMessage());
        }
        return Response::redirect($meta['base'] . '/' . $id);
    }

    /** Promote staging files to the production parent (restart to apply). */
    protected function syncFor(int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app) {
            return Response::redirect($meta['base']);
        }
        $account = $this->accountForApp($app);
        try {
            if (!$account) {
                throw new \RuntimeException('Application has no account.');
            }
            app(AppService::class)->syncToProduction($account, $app, $this->asAdmin());
            app('session')->flash('success', 'Staging files promoted — restart production to apply, then run Update for fresh dependencies.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Sync failed: ' . $e->getMessage());
        }
        return Response::redirect($meta['base'] . '/' . $id);
    }

    protected function packageInstallFor(Request $request, int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app || ($app['runtime'] ?? '') !== 'python') {
            app('session')->flash('error', 'Packages are only available for Python apps.');
            return Response::redirect($meta['base']);
        }
        $package = strtolower(trim((string) $request->input('package', '')));
        try {
            AppService::normalizePackage($package);
            app('db')->insert('jobs', [
                'type' => 'python.package.install',
                'status' => 'pending',
                'payload' => json_encode(['application_id' => $id, 'package' => $package]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 2,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('app.package.install.queued', 'application', $id, ['package' => $package]);
            app('session')->flash('success', "Package {$package} queued — it installs into the app's virtualenv in the background.");
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue package install: ' . $e->getMessage());
        }
        return Response::redirect($meta['base'] . '/' . $id);
    }

    protected function packageRemoveFor(Request $request, int $id, string $runtime): Response
    {
        $meta = $this->meta($runtime);
        $app = $this->loadScoped($id, $runtime);
        if (!$app || ($app['runtime'] ?? '') !== 'python') {
            app('session')->flash('error', 'Packages are only available for Python apps.');
            return Response::redirect($meta['base']);
        }
        $package = strtolower(trim((string) $request->input('package', '')));
        try {
            AppService::normalizePackage($package);
            app('db')->insert('jobs', [
                'type' => 'python.package.uninstall',
                'status' => 'pending',
                'payload' => json_encode(['application_id' => $id, 'package' => $package]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 2,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('app.package.uninstall.queued', 'application', $id, ['package' => $package]);
            app('session')->flash('success', "Package {$package} queued for removal.");
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue package removal: ' . $e->getMessage());
        }
        return Response::redirect($meta['base'] . '/' . $id);
    }

    // ------------------------------------------------------------ legacy routing

    protected function legacyIndexFor(): Response
    {
        return Response::redirect($this->areaPrefix() . '/nodejs');
    }

    protected function legacyCreateFor(): Response
    {
        return Response::redirect($this->areaPrefix() . '/nodejs/create');
    }

    protected function legacyShowFor(int $id): Response
    {
        $app = $this->findApp($id);
        if (!$app) {
            return Response::redirect($this->areaPrefix() . '/nodejs');
        }
        return Response::redirect($this->baseForApp($app) . '/' . $id);
    }

    /** Stale forms POST here: resolve the runtime and run the real handler. */
    protected function legacyRuntimeFor(int $id): string
    {
        $app = $this->findApp($id);
        return ($app && ($app['runtime'] ?? '') === 'python') ? 'python' : 'node';
    }

    protected function legacyStoreFor(Request $request): Response
    {
        $rt = (string) $request->input('runtime', 'node') === 'python' ? 'python' : 'node';
        return $this->storeFor($request, $rt);
    }

    // ------------------------------------------------------------ helpers

    protected function loadScoped(int $id, string $runtime): ?array
    {
        $app = $this->findApp($id);
        return ($app && ($app['runtime'] ?? '') === $runtime) ? $app : null;
    }

    /** Staging parent row (name for the sync UI), or null. */
    protected function parentFor(array $app): ?array
    {
        $pid = (int) ($app['cloned_from'] ?? 0);
        if ($pid <= 0) {
            return null;
        }
        $parent = app('db')->selectOne('SELECT * FROM `applications` WHERE `id`=?', [$pid]);
        return ($parent && (int) $parent['account_id'] === (int) $app['account_id']) ? $parent : null;
    }

    protected function backWithErrors(Request $r, ValidationException $e, string $path): Response
    {
        app('session')->flashInput($r->all());
        foreach ($e->errors() as $f => $ms) {
            foreach ($ms as $m) {
                app('session')->pushError($f, $m);
            }
        }
        return Response::redirect($path);
    }
}
