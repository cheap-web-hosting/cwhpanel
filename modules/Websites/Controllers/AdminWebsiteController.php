<?php

declare(strict_types=1);

namespace Modules\Websites\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use App\Foundation\ValidationException;
use Modules\Websites\Services\NginxConfigGenerator;
use Modules\Websites\Services\VhostWriter;

class AdminWebsiteController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND (w.`label` LIKE ? OR d.`name` LIKE ?)';
            array_push($params, '%' . $search . '%', '%' . $search . '%');
        }

        $websites = app('db')->select(
            "SELECT w.*, d.`name` AS domain_name, a.`username`
             FROM `websites` w
             LEFT JOIN `domains` d ON d.`id` = w.`domain_id`
             LEFT JOIN `accounts` a ON a.`id` = w.`account_id`
             WHERE {$where} ORDER BY d.`name`",
            $params
        );

        $content = view('admin/websites/index', [
            'title' => 'Websites',
            'websites' => $websites,
            'search' => $search,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function create(): Response
    {
        [$accounts, $domains] = $this->formData();

        $content = view('admin/websites/form', [
            'title' => 'Add Website',
            'website' => null,
            'old' => app('session')->get('_old', []),
            'accounts' => $accounts,
            'domains' => $domains,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function store(Request $request): Response
    {
        try {
            $data = $request->validate([
                'label' => 'required|max:191',
                'account_id' => 'required|integer',
                'domain_id' => 'required|integer',
                'document_root' => 'required|max:255',
                'php_version' => 'max:16',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/websites/create');
        }

        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $data['account_id']]);
        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) $data['domain_id']]);

        if ($account === null || $domain === null) {
            app('session')->flashInput($request->only(['label', 'account_id', 'domain_id', 'document_root', 'php_version']));
            app('session')->flash('error', 'Invalid account or domain.');
            return Response::redirect('/admin/websites/create');
        }

        if ((int) $domain['account_id'] !== (int) $account['id']) {
            app('session')->flash('error', 'Selected domain does not belong to the selected account.');
            return Response::redirect('/admin/websites/create');
        }

        if (app('db')->selectOne('SELECT `id` FROM `websites` WHERE `domain_id` = ?', [(int) $domain['id']])) {
            app('session')->flash('error', 'A website already exists for this domain (one website row per domain).');
            return Response::redirect('/admin/websites/create');
        }

        $documentRoot = trim((string) $data['document_root']);
        $home = rtrim((string) ($account['home_dir'] ?? '/home/' . $account['username']), '/');
        if ($documentRoot === '' || $documentRoot[0] !== '/' || str_contains($documentRoot, '..') || ($documentRoot !== $home && !str_starts_with($documentRoot, $home . '/'))) {
            app('session')->flash('error', 'Document root must be an absolute path inside the account home (' . $home . ').');
            return Response::redirect('/admin/websites/create');
        }

        $phpVersion = trim((string) ($data['php_version'] ?? ''));
        if ($phpVersion === '') {
            // Blank = inherit the account's package default.
            $phpVersion = \Modules\Core\Services\PhpVersionsService::packageDefaultForAccount((int) $account['id']);
        }
        if (!\Modules\Core\Services\PhpVersionsService::isAvailable($phpVersion)) {
            app('session')->flash('error', 'Unsupported PHP version.');
            return Response::redirect('/admin/websites/create');
        }

        $website = [
            'label' => $data['label'],
            'document_root' => $documentRoot,
            'php_version' => $phpVersion,
        ];

        $nginx = app(NginxConfigGenerator::class)->generate($website, $domain, $account);

        $id = app('db')->insert('websites', [
            'label' => $data['label'],
            'account_id' => (int) $data['account_id'],
            'domain_id' => (int) $data['domain_id'],
            'document_root' => $documentRoot,
            'php_version' => $phpVersion,
            'ssl_enabled' => 0,
            'nginx_config' => $nginx,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        event('WebsiteCreated', ['website_id' => $id, 'domain_id' => (int) $data['domain_id']]);
        audit('website.created', 'website', $id, ['label' => $data['label']]);

        try {
            app(\Modules\Core\Services\NginxIsolationService::class)->pushVhost($account, $domain['name'], $nginx);
            app('session')->flash('success', 'Website created.');
        } catch (\Throwable $e) {
            app('logger')->warning('Website created but Nginx vhost write failed: ' . $e->getMessage());
            app('session')->flash('error', 'Website created, but Nginx config could not be applied: ' . $e->getMessage());
        }

        return Response::redirect('/admin/websites/' . $id);
    }

    public function show(int $id): Response
    {
        $website = $this->find($id);

        if ($website === null) {
            return Response::redirect('/admin/websites');
        }

        $privacy = app(\Modules\Websites\Services\PrivacyService::class)->forWebsite($id);

        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) ($website['account_id'] ?? 0)]);
        $isolated = $account !== null && app(\Modules\Core\Services\NginxIsolationService::class)->isEnabled($account);
        try {
            $logs = app(\Modules\Monitoring\Services\TrafficService::class)->tailLogs($website);
        } catch (\Throwable $e) {
            $logs = null;
        }
        $rateLimit = null;
        $rateStats = null;
        try {
            $rateLimit = app(\Modules\Websites\Services\RateLimitService::class)->effective($website, $account);
            $rateStats = app(\Modules\Websites\Services\RateLimitService::class)->statsForWebsite($website);
        } catch (\Throwable $e) {
            $rateLimit = null;
        }

        $content = view('admin/websites/show', [
            'title' => 'Website: ' . $website['label'],
            'website' => $website,
            'has_cert' => $this->siteHasCert($website),
            'phpDirs' => $this->phpDirsFor($id),
            'phpVersions' => $this->phpVersionsForForm(),
            'phpOptions' => \Modules\Core\Services\PhpVersionsService::OPTIONS,
            'privacy' => $privacy['dirs'],
            'logs' => $logs,
            'isolated' => $isolated,
            'rateLimit' => $rateLimit,
            'rateStats' => $rateStats,
            'leech' => $this->leechSuspects($id),
            'ipEnabled' => $this->ipEnabled(),
            'ipPool' => $this->ipPoolForForm(),
            'sharedIp' => (string) env('PANEL_PUBLIC_IP', ''),
            'accountIpv6' => (string) (app(\Modules\Network\Services\IpAllocatorService::class)->effectiveIpv6($account) ?? ''),
        ], 'layouts/admin');

        return new Response((string)$content);
    }

    /** Whether IP management is on (section degrades to hidden when off). */
    private function ipEnabled(): bool
    {
        try {
            return app(\Modules\Network\Services\IpAllocatorService::class)->enabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<int, array> never throws (selector degrades to shared-only) */
    private function ipPoolForForm(): array
    {
        try {
            return app(\Modules\Network\Services\IpAllocatorService::class)->pool();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Assign (or clear, with empty value) this website's dedicated IPv4.
     * Goes through the allocator: pool bookkeeping, bind, vhost push and
     * DNS reprovision happen there, never partially here.
     */
    public function ipv4Save(int $id, Request $request): Response
    {
        if ($this->findOrRedirect($id) === null) {
            return Response::redirect('/admin/websites');
        }
        $address = trim((string) $request->input('ipv4_address', ''));
        try {
            app(\Modules\Network\Services\IpAllocatorService::class)
                ->assignWebsiteIpv4($id, $address === '' ? null : $address);
            app('session')->flash('success', $address === ''
                ? 'Dedicated IPv4 removed — back on the shared fallback.'
                : 'Dedicated IPv4 assigned and applied (vhost + DNS).');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'IPv4 not changed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function logsDownload(int $id, Request $request): Response
    {
        $website = $this->find($id);
        if ($website === null) {
            return Response::redirect('/admin/websites');
        }
        $which = (string) $request->query('which', 'access');
        if (!in_array($which, ['access', 'error'], true)) {
            return Response::redirect('/admin/websites/' . $id);
        }
        try {
            $tails = app(\Modules\Monitoring\Services\TrafficService::class)->tailLogs($website, 2097152);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Log unavailable: ' . $e->getMessage());
            return Response::redirect('/admin/websites/' . $id);
        }
        $text = $tails[$which] ?? '';
        $file = $tails[$which . 'File'] ?? ($website['label'] . '.' . $which . '.log');
        return (new Response($text))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename=\"' . \App\Foundation\Response::safeFilename((string) $file) . '\"');
    }

    public function workerRestart(int $id): Response
    {
        $website = $this->find($id);
        if ($website === null) {
            return Response::redirect('/admin/websites');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) ($website['account_id'] ?? 0)]);
        if ($account === null || !app(\Modules\Core\Services\NginxIsolationService::class)->isEnabled($account)) {
            app('session')->flash('error', 'No dedicated worker for this website.');
            return Response::redirect('/admin/websites/' . $id);
        }
        try {
            app(\Modules\Core\Services\NginxIsolationService::class)->startWorker($account);
            audit('nginx.worker.restarted', 'website', $id);
            app('session')->flash('success', 'Web server restarted.');
        } catch (\Throwable $e) {
            app('logger')->warning('Worker restart failed: ' . $e->getMessage());
            app('session')->flash('error', 'Restart failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function maintenanceToggle(int $id, Request $request): Response
    {
        if ($this->findOrRedirect($id) === null) {
            return Response::redirect('/admin/websites');
        }
        $enabled = (bool) $request->input('enabled');
        app('db')->update('websites', [
            'maintenance_mode' => $enabled ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->regenerate($id);
            audit($enabled ? 'website.maintenance.enabled' : 'website.maintenance.disabled', 'website', $id);
            app('session')->flash('success', $enabled ? 'Maintenance mode on (503 for all requests).' : 'Maintenance mode off.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Toggle saved but vhost push failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    /**
     * Per-site HTTPS settings: force-HTTPS redirect (cleartext 301, ACME
     * path exempt) and HSTS max-age (TLS responses only). Pushes through
     * PrivacyService::regenerate so shared and isolated (front-door +
     * worker) vhosts stay in agreement.
     */
    public function httpsSave(int $id, Request $request): Response
    {
        if ($this->findOrRedirect($id) === null) {
            return Response::redirect('/admin/websites');
        }
        $force = (bool) $request->input('force_https');
        $hsts = max(0, min(63072000, (int) $request->input('hsts_max_age')));
        app('db')->update('websites', [
            'force_https' => $force ? 1 : 0,
            'hsts_max_age' => $hsts,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->regenerate($id);
            audit('website.https.updated', 'website', $id, ['force' => $force, 'hsts' => $hsts]);
            app('session')->flash('success', 'HTTPS settings saved and applied.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Settings saved but vhost push failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    /**
     * Clear a bandwidth billing hold early. Warns honestly: the next daily
     * sweep re-suspends while the account is still over quota.
     */
    public function bandwidthUnsuspend(int $id): Response
    {
        $site = $this->findOrRedirect($id);
        if ($site === null) {
            return Response::redirect('/admin/websites');
        }
        app('db')->update('websites', [
            'suspended_bandwidth' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->regenerate($id);
            audit('website.bandwidth.unsuspended', 'website', $id);
            app('session')->flash('success', 'Billing hold cleared. Note: the next daily sweep re-suspends while over quota.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Hold cleared but vhost push failed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function customNginxSave(int $id, Request $request): Response
    {
        if ($this->findOrRedirect($id) === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            app(\Modules\Core\Services\NginxIsolationService::class)->saveCustomNginx(
                $id, (string) $request->input('custom_nginx', '')
            );
            audit('website.custom_nginx.updated', 'website', $id);
            app('session')->flash('success', 'Custom directives applied.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function rateLimitSave(int $id, Request $request): Response
    {
        if ($this->findOrRedirect($id) === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            [$enabled, $rps, $burst, $conn] = $this->parseRateLimit($request);
            app(\Modules\Websites\Services\RateLimitService::class)->save($id, $enabled, $rps, $burst, $conn);
            audit('website.ratelimit.updated', 'website', $id, ['enabled' => $enabled, 'rps' => $rps, 'burst' => $burst, 'connections' => $conn]);
            app('session')->flash('success', $enabled ? 'Bot shield enabled.' : 'Bot shield disabled.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    /** @return array{0:bool, 1:?int, 2:?int} */
    private function parseRateLimit(Request $request): array
    {
        $enabled = (bool) $request->input('enabled');
        $rpsRaw = trim((string) $request->input('rate_limit_rps', ''));
        $burstRaw = trim((string) $request->input('rate_limit_burst', ''));
        $rps = $rpsRaw === '' ? null : (int) $rpsRaw;
        $burst = $burstRaw === '' ? null : (int) $burstRaw;
        $connRaw = trim((string) $request->input('max_connections', ''));
        $conn = $connRaw === '' ? null : (int) $connRaw;
        if ($rps !== null && ($rps < 0 || $rps > 1000)) {
            throw new \RuntimeException('Requests/sec must be 0 (unlimited) or 1-1000 (empty = package default).');
        }
        if ($burst !== null && ($burst < 0 || $burst > 5000)) {
            throw new \RuntimeException('Burst must be 0-5000 (empty = package default).');
        }
        return [$enabled, $rps, $burst, $conn];
    }

    private function findOrRedirect(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * Whether the site's domain has a certificate installed (same file
     * paths the vhost generator keys its 443 sibling on).
     */
    private function siteHasCert(array $website): bool
    {
        $domain = (string) ($website['domain_name'] ?? $website['label'] ?? '');
        return $domain !== ''
            && is_file('/etc/cwhpanel/ssl/' . $domain . '/fullchain.pem')
            && is_file('/etc/cwhpanel/ssl/' . $domain . '/key.pem');
    }

    /** @return array<int, array> never throws (section degrades to empty) */
    private function phpDirsFor(int $id): array
    {
        try {
            return app(\Modules\Websites\Services\PhpDirService::class)->forWebsite($id);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Installed versions for the override form; empty when the agent is down. */
    private function phpVersionsForForm(): array
    {
        try {
            return app(\Modules\Core\Services\PhpVersionsService::class)->installed();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int,array> never throws (log failures degrade to empty) */
    private function leechSuspects(int $id): array
    {
        try {
            return app(\Modules\Websites\Services\LeechService::class)->suspects($id);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function findProtDir(int $id, int $dirId): ?array
    {
        return app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ?',
            [$dirId, $id]
        );
    }

    private function findProtUser(int $id, int $dirId, int $userId): ?array
    {
        return app('db')->selectOne(
            'SELECT u.* FROM `website_dir_users` u
              JOIN `website_protected_dirs` d ON d.`id` = u.`protected_dir_id`
              JOIN `websites` w ON w.`id` = d.`website_id`
              WHERE u.`id` = ? AND d.`id` = ? AND w.`id` = ?',
            [$userId, $dirId, $id]
        );
    }

    public function leechMax(int $id, int $dirId, Request $request): Response
    {
        if ($this->findProtDir($id, $dirId) === null) {
            return Response::redirect('/admin/websites');
        }
        $raw = trim((string) $request->input('max_logins', ''));
        try {
            app(\Modules\Websites\Services\LeechService::class)->setMaxLogins($dirId, $raw === '' ? null : (int) $raw);
            audit('privacy.leech.updated', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'Leech protection updated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function leechSuspend(int $id, int $dirId, int $userId): Response
    {
        if ($this->findProtUser($id, $dirId, $userId) === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            app(\Modules\Websites\Services\LeechService::class)->suspend($userId);
            audit('privacy.leech.suspended', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'User suspended; their password no longer works.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function leechUnsuspend(int $id, int $dirId, int $userId): Response
    {
        if ($this->findProtUser($id, $dirId, $userId) === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            app(\Modules\Websites\Services\LeechService::class)->unsuspend($userId);
            audit('privacy.leech.unsuspended', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'User reinstated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function privacyAddDir(int $id, Request $request): Response
    {
        if ($this->findOrRedirect($id) === null) {
            return Response::redirect('/admin/websites');
        }
        $svc = app(\Modules\Websites\Services\PrivacyService::class);
        try {
            $dirId = $svc->addDir($id, (string) $request->input('path', ''), (string) $request->input('title', ''));
            $svc->syncHtpasswd($id);
            $svc->regenerate($id);
            audit('privacy.dir.added', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'Protected directory added.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function privacyDeleteDir(int $id, int $dirId): Response
    {
        $dir = app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ?',
            [$dirId, $id]
        );
        if ($dir === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->deleteDir($dirId);
            audit('privacy.dir.deleted', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'Protected directory removed.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function privacyToggleDir(int $id, int $dirId, Request $request): Response
    {
        $dir = app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ?',
            [$dirId, $id]
        );
        if ($dir === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->toggleDir($dirId, (bool) $request->input('enabled'));
            app('session')->flash('success', 'Updated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function privacyAddUser(int $id, int $dirId, Request $request): Response
    {
        $dir = app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ?',
            [$dirId, $id]
        );
        if ($dir === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->addUser(
                $dirId, (string) $request->input('username', ''), (string) $request->input('password', '')
            );
            audit('privacy.user.added', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'User added.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function privacyDeleteUser(int $id, int $dirId, int $userId): Response
    {
        $dir = app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ?',
            [$dirId, $id]
        );
        if ($dir === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->deleteUser($dirId, $userId);
            audit('privacy.user.deleted', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'User removed.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function edit(int $id): Response
    {
        $website = $this->find($id);

        if ($website === null) {
            return Response::redirect('/admin/websites');
        }

        [$accounts, $domains] = $this->formData();

        $content = view('admin/websites/form', [
            'title' => 'Edit Website',
            'website' => $website,
            'old' => app('session')->get('_old', []),
            'accounts' => $accounts,
            'domains' => $domains,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function update(int $id, Request $request): Response
    {
        $website = $this->find($id);

        if ($website === null) {
            return Response::redirect('/admin/websites');
        }

        try {
            $data = $request->validate([
                'label' => 'required|max:191',
                'document_root' => 'required|max:255',
                'php_version' => 'max:16',
            ]);
        } catch (ValidationException $e) {
            return $this->backWithErrors($request, $e, '/admin/websites/' . $id . '/edit');
        }

        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $website['account_id']]);
        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id` = ?', [(int) $website['domain_id']]);

        $documentRoot = trim((string) $data['document_root']);
        $home = rtrim((string) ($account['home_dir'] ?? '/home/' . ($account['username'] ?? '')), '/');
        if ($documentRoot === '' || $documentRoot[0] !== '/' || str_contains($documentRoot, '..') || ($documentRoot !== $home && !str_starts_with($documentRoot, $home . '/'))) {
            app('session')->flash('error', 'Document root must be an absolute path inside the account home (' . $home . ').');
            return Response::redirect('/admin/websites/' . $id . '/edit');
        }

        $newVersion = (string) ($data['php_version'] ?: $website['php_version']);
        if (!\Modules\Core\Services\PhpVersionsService::isAvailable($newVersion)) {
            app('session')->flash('error', 'Unsupported PHP version.');
            return Response::redirect('/admin/websites/' . $id . '/edit');
        }
        $oldVersion = (string) ($website['php_version'] ?? '8.4');
        if ($newVersion !== $oldVersion) {
            // Preserve the site's stored PHP options across the version move.
            $stored = json_decode((string) ($website['php_settings'] ?? '{}'), true) ?: [];
            try {
                $this->ensurePhpPool($account, $newVersion, $stored);
                app(\Modules\Core\Services\PhpVersionsService::class)->pruneVersion($account, $oldVersion);
            } catch (\Throwable $e) {
                app('logger')->warning('PHP pool provision failed: ' . $e->getMessage());
                app('session')->flash('error', 'PHP version not changed (pool provisioning failed): ' . $e->getMessage());
                return Response::redirect('/admin/websites/' . $id . '/edit');
            }
        }

        $nginx = app(NginxConfigGenerator::class)->generate(array_merge($website, [
            'label' => $data['label'],
            'document_root' => $documentRoot,
            'php_version' => $newVersion,
        ]), $domain, $account);

        app('db')->update('websites', [
            'label' => $data['label'],
            'document_root' => $documentRoot,
            'php_version' => $newVersion,
            'nginx_config' => $nginx,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);

        event('WebsiteUpdated', ['website_id' => $id]);
        audit('website.updated', 'website', $id);

        try {
            app(\Modules\Core\Services\NginxIsolationService::class)->pushVhost($account, $website['domain_name'], $nginx);
            app('session')->flash('success', 'Website updated.');
        } catch (\Throwable $e) {
            app('logger')->warning('Website updated but Nginx vhost write failed: ' . $e->getMessage());
            app('session')->flash('error', 'Website updated, but Nginx config could not be applied: ' . $e->getMessage());
        }

        return Response::redirect('/admin/websites/' . $id);
    }

    public function destroy(int $id): Response
    {
        $website = $this->find($id);

        if ($website === null) {
            return Response::redirect('/admin/websites');
        }

        app('db')->delete('websites', '`id` = ?', [$id]);
        event('WebsiteDeleted', ['website_id' => $id]);
        audit('website.deleted', 'website', $id, ['label' => $website['label']]);

        try {
            app(VhostWriter::class)->delete($website['domain_name']);
        } catch (\Throwable $e) {
            app('logger')->warning('Nginx vhost delete failed: ' . $e->getMessage());
        }

        app('session')->flash('success', 'Website deleted.');

        return Response::redirect('/admin/websites');
    }

    public function nginx(int $id): Response
    {
        $website = $this->find($id);

        if ($website === null) {
            return Response::redirect('/admin/websites');
        }

        $content = view('admin/websites/nginx', [
            'title' => 'Nginx Config: ' . $website['label'],
            'website' => $website,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    private function find(int $id): ?array
    {
        return app('db')->selectOne(
            'SELECT w.*, d.`name` AS domain_name, a.`username` FROM `websites` w
             LEFT JOIN `domains` d ON d.`id` = w.`domain_id`
             LEFT JOIN `accounts` a ON a.`id` = w.`account_id`
             WHERE w.`id` = ?',
            [$id]
        );
    }

    private function formData(): array
    {
        $accounts = app('db')->select('SELECT * FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);
        $domains = app('db')->select('SELECT * FROM `domains` WHERE `status` = ? ORDER BY `name`', ['active']);

        return [$accounts, $domains];
    }

    private function ensurePhpPool(array $account, string $version, ?array $settings = null): void
    {
        $svc = app(\Modules\Core\Services\PhpVersionsService::class);
        $svc->ensurePool($account, $version, $settings);
    }

    /**
     * Save a per-directory PHP override (admin bypasses the package gate).
     */
    public function phpDirSave(int $id, Request $request): Response
    {
        if ($this->findOrRedirect($id) === null) {
            return Response::redirect('/admin/websites');
        }
        $version = (string) $request->input('dir_php_version', '');
        $version = ($version === '' || $version === 'inherit') ? null : $version;
        $settings = [];
        foreach (\Modules\Core\Services\PhpVersionsService::OPTIONS as $key => [$label, $type, $default]) {
            $raw = $request->input('dir_' . $key, null);
            if ($raw === null) {
                continue;
            }
            $settings[$key] = (string) $raw;
        }
        try {
            app(\Modules\Websites\Services\PhpDirService::class)->save($id, (string) $request->input('dir', ''), $version, $settings, true);
            app('session')->flash('success', 'Directory override saved and applied.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Directory override not saved: ' . $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    public function phpDirDelete(int $id, Request $request): Response
    {
        if ($this->findOrRedirect($id) === null) {
            return Response::redirect('/admin/websites');
        }
        try {
            app(\Modules\Websites\Services\PhpDirService::class)->delete($id, (int) $request->input('dir_id', 0), true);
            app('session')->flash('success', 'Directory override removed — the directory follows the site again.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Override not removed: ' . $e->getMessage());
        }
        return Response::redirect('/admin/websites/' . $id);
    }

    private function backWithErrors(Request $request, ValidationException $e, string $path): Response
    {
        app('session')->flashInput($request->only(['label', 'account_id', 'domain_id', 'document_root', 'php_version']));
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $msg) {
                app('session')->pushError($field, $msg);
            }
        }
        return Response::redirect($path);
    }
}
