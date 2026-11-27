<?php
declare(strict_types=1);
namespace Modules\Applications\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;

/**
 * Admin runtime apps, split into Node.js / Python lists mirroring the
 * customer pages. Legacy /admin/applications* URLs redirect to their
 * runtime-correct successor. All shared behavior lives in
 * ApplicationControllerBase; this class supplies the admin scope (all
 * accounts, search, account selection on create, asAdmin=true, audit).
 */
class AdminApplicationController extends ApplicationControllerBase
{
    protected function areaPrefix(): string
    {
        return '/admin';
    }

    protected function layout(): string
    {
        return 'layouts/admin';
    }

    protected function viewArea(string $template): string
    {
        return 'admin/' . $template;
    }

    protected function asAdmin(): bool
    {
        return true;
    }

    protected function indexRows(string $runtime, string $search): array
    {
        $where = 'app.`runtime`=?';
        $params = [$runtime];
        if ($search !== '') {
            $where .= ' AND (app.`name` LIKE ? OR a.`username` LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        return app('db')->select("SELECT app.*, a.`username` FROM `applications` app LEFT JOIN `accounts` a ON a.`id`=app.`account_id` WHERE {$where} ORDER BY app.`name`", $params);
    }

    protected function indexExtraViewData(string $search): array
    {
        return ['search' => $search];
    }

    protected function createAccounts(): array
    {
        return app('db')->select('SELECT * FROM `accounts` WHERE `status`=? ORDER BY `username`', ['active']);
    }

    protected function createAccount(array $data, string $type, ?array $parent): array
    {
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id`=?', [(int) ($data['account_id'] ?? 0)]);
        return [$account ?: null, null];
    }

    protected function accountForApp(array $app): ?array
    {
        return app('db')->selectOne('SELECT * FROM `accounts` WHERE `id`=?', [(int) $app['account_id']]) ?: null;
    }

    protected function findApp(int $id): ?array
    {
        return app('db')->selectOne(
            'SELECT app.*, a.`username` FROM `applications` app LEFT JOIN `accounts` a ON a.`id`=app.`account_id` WHERE app.`id`=?',
            [$id]
        ) ?: null;
    }

    protected function auditControl(string $action, int $id): void
    {
        audit($action, 'application', $id);
    }

    // ------------------------------------------------------------ Node.js
    public function nodeIndex(Request $request): Response { return $this->indexFor('node'); }
    public function nodeCreate(): Response { return $this->createFor('node'); }
    public function nodeStore(Request $request): Response { return $this->storeFor($request, 'node'); }
    public function nodeShow(int $id): Response { return $this->showFor($id, 'node'); }
    public function nodeStart(int $id): Response { return $this->startFor($id, 'node'); }
    public function nodeStop(int $id): Response { return $this->stopFor($id, 'node'); }
    public function nodeRestart(int $id): Response { return $this->restartFor($id, 'node'); }
    public function nodeDeploy(int $id): Response { return $this->deployFor($id, 'node'); }
    public function nodeDestroy(int $id): Response { return $this->destroyFor($id, 'node'); }
    public function nodeWebhook(int $id, Request $request): Response { return $this->webhookFor($id, $request, 'node'); }
    public function nodeWebhookRotate(int $id): Response { return $this->webhookRotateFor($id, 'node'); }
    public function nodeEnv(int $id, Request $request): Response { return $this->envSaveFor($id, $request, 'node'); }
    public function nodeUpdate(int $id): Response { return $this->updateFor($id, 'node'); }
    public function nodeClone(int $id, Request $request): Response { return $this->cloneFor($id, $request, 'node'); }
    public function nodeSync(int $id): Response { return $this->syncFor($id, 'node'); }

    // ------------------------------------------------------------ Python
    public function pythonIndex(Request $request): Response { return $this->indexFor('python'); }
    public function pythonCreate(): Response { return $this->createFor('python'); }
    public function pythonStore(Request $request): Response { return $this->storeFor($request, 'python'); }
    public function pythonShow(int $id): Response { return $this->showFor($id, 'python'); }
    public function pythonStart(int $id): Response { return $this->startFor($id, 'python'); }
    public function pythonStop(int $id): Response { return $this->stopFor($id, 'python'); }
    public function pythonRestart(int $id): Response { return $this->restartFor($id, 'python'); }
    public function pythonDeploy(int $id): Response { return $this->deployFor($id, 'python'); }
    public function pythonDestroy(int $id): Response { return $this->destroyFor($id, 'python'); }
    public function pythonWebhook(int $id, Request $request): Response { return $this->webhookFor($id, $request, 'python'); }
    public function pythonWebhookRotate(int $id): Response { return $this->webhookRotateFor($id, 'python'); }
    public function pythonEnv(int $id, Request $request): Response { return $this->envSaveFor($id, $request, 'python'); }
    public function pythonUpdate(int $id): Response { return $this->updateFor($id, 'python'); }
    public function pythonClone(int $id, Request $request): Response { return $this->cloneFor($id, $request, 'python'); }
    public function pythonSync(int $id): Response { return $this->syncFor($id, 'python'); }
    public function pythonPackageInstall(int $id, Request $request): Response { return $this->packageInstallFor($request, $id, 'python'); }
    public function pythonPackageRemove(int $id, Request $request): Response { return $this->packageRemoveFor($request, $id, 'python'); }

    // ------------------------------------------------------------ legacy
    public function legacyIndex(): Response { return $this->legacyIndexFor(); }
    public function legacyCreate(): Response { return $this->legacyCreateFor(); }
    public function legacyShow(int $id): Response { return $this->legacyShowFor($id); }
    public function legacyStore(Request $request): Response { return $this->legacyStoreFor($request); }
    public function legacyStart(int $id): Response { return $this->startFor($id, $this->legacyRuntimeFor($id)); }
    public function legacyStop(int $id): Response { return $this->stopFor($id, $this->legacyRuntimeFor($id)); }
    public function legacyRestart(int $id): Response { return $this->restartFor($id, $this->legacyRuntimeFor($id)); }
    public function legacyDeploy(int $id): Response { return $this->deployFor($id, $this->legacyRuntimeFor($id)); }
    public function legacyDestroy(int $id): Response { return $this->destroyFor($id, $this->legacyRuntimeFor($id)); }

    // ------------------------------------------------------------ admin-only
    public function runtimes(): Response
    {
        $svc = app(\Modules\Applications\Services\AppService::class);
        try {
            $installed = $svc->installedRuntimes();
        } catch (\Throwable $e) {
            $installed = ['node' => [], 'python' => []];
            app('session')->flash('error', 'Runtime probe failed: ' . $e->getMessage());
        }
        $content = view('admin/applications/runtimes', [
            'title' => 'Runtimes',
            'installed' => $installed,
            'catalog' => \Modules\Applications\Services\AppService::RUNTIMES,
            'probeErrors' => $svc->runtimeProbeErrors(),
        ], 'layouts/admin');
        return new Response((string) $content);
    }

    public function installRuntime(Request $request): Response
    {
        $runtime = (string) $request->input('runtime', '');
        $version = (string) $request->input('version', '');
        if (!isset(\Modules\Applications\Services\AppService::RUNTIMES[$runtime][$version])) {
            app('session')->flash('error', 'Unknown runtime version.');
            return Response::redirect('/admin/runtimes');
        }
        try {
            app('db')->insert('jobs', [
                'type' => 'app.runtime.install',
                'status' => 'pending',
                'payload' => json_encode(['runtime' => $runtime, 'version' => $version]),
                'result' => null,
                'attempts' => 0,
                'max_attempts' => 2,
                'available_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('app.runtime.install.queued', 'runtime', null, ['runtime' => $runtime, 'version' => $version]);
            app('session')->flash('success', "Runtime install queued ({$runtime} {$version}). It will complete in the background.");
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Could not queue runtime install: ' . $e->getMessage());
        }
        return Response::redirect('/admin/runtimes');
    }
}
