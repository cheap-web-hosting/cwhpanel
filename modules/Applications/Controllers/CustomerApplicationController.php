<?php
declare(strict_types=1);
namespace Modules\Applications\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;

/**
 * Customer runtime apps, split by runtime so the panel reads "Node.js" and
 * "Python" instead of one confusing "Applications" page (which collided with
 * the App Installer). Both pages share the `applications` feature gate and
 * the same `applications` table — only the listing scope and the fixed
 * runtime on create differ. Legacy /panel/applications* URLs redirect to
 * their runtime-correct successor. All behavior lives in
 * ApplicationControllerBase; this class supplies the customer scope.
 */
class CustomerApplicationController extends ApplicationControllerBase
{
    protected function areaPrefix(): string
    {
        return '/panel';
    }

    protected function layout(): string
    {
        return 'layouts/customer';
    }

    protected function viewArea(string $template): string
    {
        return 'customer/' . $template;
    }

    protected function asAdmin(): bool
    {
        return false;
    }

    protected function indexRows(string $runtime, string $search): array
    {
        $accountId = $this->accountId();
        if ($accountId === null) {
            return [];
        }
        return app('db')->select('SELECT * FROM `applications` WHERE `account_id`=? AND `runtime`=? ORDER BY `name`', [$accountId, $runtime]);
    }

    protected function indexExtraViewData(string $search): array
    {
        return [];
    }

    protected function createAccounts(): array
    {
        return [];
    }

    protected function createAccount(array $data, string $type, ?array $parent): array
    {
        $accountId = $this->accountId();
        if ($accountId === null) {
            return [null, Response::redirect('/panel')];
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id`=?', [$accountId]);
        return [$account ?: null, null];
    }

    protected function accountForApp(array $app): ?array
    {
        return $this->account();
    }

    protected function findApp(int $id): ?array
    {
        $accountId = $this->accountId();
        if ($accountId === null) {
            return null;
        }
        return app('db')->selectOne('SELECT * FROM `applications` WHERE `id`=? AND `account_id`=?', [$id, $accountId]) ?: null;
    }

    protected function auditControl(string $action, int $id): void
    {
        // Customer panel does not audit control actions (admin does).
    }

    // ------------------------------------------------------------ Node.js
    public function nodeIndex(): Response { return $this->indexFor('node'); }
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
    public function pythonIndex(): Response { return $this->indexFor('python'); }
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

    // ------------------------------------------------------------ helpers
    private function accountId(): ?int
    {
        $user = app('auth')->user();
        if ($user === null) return null;
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id`=?', [$user->id]);
        return $row ? (int) $row['id'] : null;
    }

    private function account(): ?array
    {
        $accountId = $this->accountId();
        return $accountId ? app('db')->selectOne('SELECT * FROM `accounts` WHERE `id`=?', [$accountId]) ?: null : null;
    }
}
