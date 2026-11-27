<?php

declare(strict_types=1);

namespace Modules\Websites\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Websites\Services\NginxConfigGenerator;
use Modules\Websites\Services\VhostWriter;

class CustomerWebsiteController
{
    private function accountId(): ?int
    {
        $user = app('auth')->user();
        if ($user === null) return null;

        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id` = ?', [$user->id]);
        return $row !== null ? (int) $row['id'] : null;
    }

    public function index(): Response
    {
        $accountId = $this->accountId();

        if ($accountId === null) {
            return Response::redirect('/panel');
        }

        $websites = app('db')->select(
            'SELECT w.*, d.`name` AS domain_name FROM `websites` w
             LEFT JOIN `domains` d ON d.`id` = w.`domain_id`
             WHERE w.`account_id` = ? ORDER BY d.`name`',
            [$accountId]
        );

        $content = view('customer/websites/index', [
            'title' => 'My Websites',
            'websites' => $websites,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function show(int $id): Response
    {
        $accountId = $this->accountId();
        $website = app('db')->selectOne(
            'SELECT w.*, d.`name` AS domain_name FROM `websites` w
             LEFT JOIN `domains` d ON d.`id` = w.`domain_id`
             WHERE w.`id` = ? AND w.`account_id` = ?',
            [$id, $accountId]
        );

        if ($website === null) {
            return Response::redirect('/panel/websites');
        }

        $privacy = app(\Modules\Websites\Services\PrivacyService::class)->forWebsite($id);

        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $website['account_id']]);
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

        $content = view('customer/websites/show', [
            'title' => 'Website: ' . $website['label'],
            'website' => $website,
            'has_cert' => $this->siteHasCert($website),
            'privacy' => $privacy['dirs'],
            'logs' => $logs,
            'isolated' => $isolated,
            'rateLimit' => $rateLimit,
            'rateStats' => $rateStats,
            'leech' => $this->leechSuspects($id),
            'ipEnabled' => $this->ipEnabled(),
            'sharedIp' => (string) env('PANEL_PUBLIC_IP', ''),
            'accountIpv6' => (string) (app(\Modules\Network\Services\IpAllocatorService::class)->effectiveIpv6($account) ?? ''),
        ], 'layouts/customer');

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

    public function logsDownload(int $id, Request $request): Response
    {
        $website = $this->ownedWebsite($id);
        if ($website === null) {
            return Response::redirect('/panel/websites');
        }
        $which = (string) $request->query('which', 'access');
        if (!in_array($which, ['access', 'error'], true)) {
            return Response::redirect('/panel/websites/' . $id);
        }
        try {
            $tails = app(\Modules\Monitoring\Services\TrafficService::class)->tailLogs($website, 2097152);
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Log unavailable: ' . $e->getMessage());
            return Response::redirect('/panel/websites/' . $id);
        }
        $text = $tails[$which] ?? '';
        $file = $tails[$which . 'File'] ?? ($website['label'] . '.' . $which . '.log');
        return (new Response($text))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename=\"' . \App\Foundation\Response::safeFilename((string) $file) . '\"');
    }

    public function workerRestart(int $id): Response
    {
        $website = $this->ownedWebsite($id);
        if ($website === null) {
            return Response::redirect('/panel/websites');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $website['account_id']]);
        if ($account === null || !app(\Modules\Core\Services\NginxIsolationService::class)->isEnabled($account)) {
            app('session')->flash('error', 'No dedicated worker for this website.');
            return Response::redirect('/panel/websites/' . $id);
        }
        try {
            app(\Modules\Core\Services\NginxIsolationService::class)->startWorker($account);
            audit('nginx.worker.restarted', 'website', $id);
            app('session')->flash('success', 'Web server restarted.');
        } catch (\Throwable $e) {
            app('logger')->warning('Worker restart failed: ' . $e->getMessage());
            app('session')->flash('error', 'Restart failed: ' . $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id);
    }

    public function maintenanceToggle(int $id, Request $request): Response
    {
        $website = $this->ownedWebsite($id);
        if ($website === null) {
            return Response::redirect('/panel/websites');
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
        return Response::redirect('/panel/websites/' . $id);
    }

    /**
     * Per-site HTTPS settings: force-HTTPS redirect (cleartext 301, ACME
     * path exempt) and HSTS max-age (TLS responses only). Same
     * regenerate path as the maintenance toggle.
     */
    public function httpsSave(int $id, Request $request): Response
    {
        $website = $this->ownedWebsite($id);
        if ($website === null) {
            return Response::redirect('/panel/websites');
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
        return Response::redirect('/panel/websites/' . $id);
    }

    public function customNginxSave(int $id, Request $request): Response
    {
        if ($this->ownedWebsite($id) === null) {
            return Response::redirect('/panel/websites');
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
        return Response::redirect('/panel/websites/' . $id);
    }

    public function rateLimitSave(int $id, Request $request): Response
    {
        if ($this->ownedWebsite($id) === null) {
            return Response::redirect('/panel/websites');
        }
        try {
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
            app(\Modules\Websites\Services\RateLimitService::class)->save($id, $enabled, $rps, $burst, $conn);
            audit('website.ratelimit.updated', 'website', $id, ['enabled' => $enabled, 'rps' => $rps, 'burst' => $burst, 'connections' => $conn]);
            app('session')->flash('success', $enabled ? 'Bot shield enabled.' : 'Bot shield disabled.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id);
    }

    private function ownedWebsite(int $id): ?array
    {
        $accountId = $this->accountId();
        return app('db')->selectOne(
            'SELECT * FROM `websites` WHERE `id` = ? AND `account_id` = ?',
            [$id, $accountId]
        );
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

    /** @return array<int,array> never throws (log failures degrade to empty) */
    private function leechSuspects(int $id): array
    {
        try {
            return app(\Modules\Websites\Services\LeechService::class)->suspects($id);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function ownedProtDir(int $id, int $dirId): ?array
    {
        return app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ? AND w.`account_id` = ?',
            [$dirId, $id, $this->accountId()]
        );
    }

    public function leechMax(int $id, int $dirId, Request $request): Response
    {
        if ($this->ownedProtDir($id, $dirId) === null) {
            return Response::redirect('/panel/websites');
        }
        $raw = trim((string) $request->input('max_logins', ''));
        try {
            app(\Modules\Websites\Services\LeechService::class)->setMaxLogins($dirId, $raw === '' ? null : (int) $raw);
            audit('privacy.leech.updated', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'Leech protection updated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id);
    }

    public function leechSuspend(int $id, int $dirId, int $userId): Response
    {
        $user = $this->ownedProtUser($id, $dirId, $userId);
        if ($user === null) {
            return Response::redirect('/panel/websites');
        }
        try {
            app(\Modules\Websites\Services\LeechService::class)->suspend($userId);
            audit('privacy.leech.suspended', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'User suspended; their password no longer works.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id);
    }

    public function leechUnsuspend(int $id, int $dirId, int $userId): Response
    {
        $user = $this->ownedProtUser($id, $dirId, $userId);
        if ($user === null) {
            return Response::redirect('/panel/websites');
        }
        try {
            app(\Modules\Websites\Services\LeechService::class)->unsuspend($userId);
            audit('privacy.leech.unsuspended', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'User reinstated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id);
    }

    private function ownedProtUser(int $id, int $dirId, int $userId): ?array
    {
        return app('db')->selectOne(
            'SELECT u.* FROM `website_dir_users` u
              JOIN `website_protected_dirs` d ON d.`id` = u.`protected_dir_id`
              JOIN `websites` w ON w.`id` = d.`website_id`
              WHERE u.`id` = ? AND d.`id` = ? AND w.`id` = ? AND w.`account_id` = ?',
            [$userId, $dirId, $id, $this->accountId()]
        );
    }

    public function privacyAddDir(int $id, Request $request): Response
    {
        if ($this->ownedWebsite($id) === null) {
            return Response::redirect('/panel/websites');
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
        return Response::redirect('/panel/websites/' . $id);
    }

    public function privacyDeleteDir(int $id, int $dirId): Response
    {
        $dir = app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ? AND w.`account_id` = ?',
            [$dirId, $id, $this->accountId()]
        );
        if ($dir === null) {
            return Response::redirect('/panel/websites');
        }
        $svc = app(\Modules\Websites\Services\PrivacyService::class);
        try {
            $svc->deleteDir($dirId);
            audit('privacy.dir.deleted', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'Protected directory removed.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id);
    }

    public function privacyToggleDir(int $id, int $dirId, Request $request): Response
    {
        $dir = app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ? AND w.`account_id` = ?',
            [$dirId, $id, $this->accountId()]
        );
        if ($dir === null) {
            return Response::redirect('/panel/websites');
        }
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->toggleDir($dirId, (bool) $request->input('enabled'));
            app('session')->flash('success', 'Updated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id);
    }

    public function privacyAddUser(int $id, int $dirId, Request $request): Response
    {
        $dir = app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ? AND w.`account_id` = ?',
            [$dirId, $id, $this->accountId()]
        );
        if ($dir === null) {
            return Response::redirect('/panel/websites');
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
        return Response::redirect('/panel/websites/' . $id);
    }

    public function privacyDeleteUser(int $id, int $dirId, int $userId): Response
    {
        $dir = app('db')->selectOne(
            'SELECT d.* FROM `website_protected_dirs` d JOIN `websites` w ON w.`id` = d.`website_id`
             WHERE d.`id` = ? AND w.`id` = ? AND w.`account_id` = ?',
            [$dirId, $id, $this->accountId()]
        );
        if ($dir === null) {
            return Response::redirect('/panel/websites');
        }
        try {
            app(\Modules\Websites\Services\PrivacyService::class)->deleteUser($dirId, $userId);
            audit('privacy.user.deleted', 'website', $id, ['dir_id' => $dirId]);
            app('session')->flash('success', 'User removed.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id);
    }

    public function destroy(int $id): Response
    {
        $accountId = $this->accountId();
        $website = app('db')->selectOne('SELECT * FROM `websites` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);

        if ($website === null) {
            return Response::redirect('/panel/websites');
        }

        app('db')->delete('websites', '`id` = ?', [$id]);
        event('WebsiteDeleted', ['website_id' => $id]);
        audit('website.deleted', 'website', $id);

        try {
            app(VhostWriter::class)->delete($website['domain_name']);
        } catch (\Throwable $e) {
            app('logger')->warning('Nginx vhost delete failed: ' . $e->getMessage());
        }

        app('session')->flash('success', 'Website deleted.');

        return Response::redirect('/panel/websites');
    }

    public function nginx(int $id): Response
    {
        $accountId = $this->accountId();
        $website = app('db')->selectOne('SELECT * FROM `websites` WHERE `id` = ? AND `account_id` = ?', [$id, $accountId]);

        if ($website === null) {
            return Response::redirect('/panel/websites');
        }

        $content = view('customer/websites/nginx', [
            'title' => 'Nginx Config: ' . $website['label'],
            'website' => $website,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    /** Normalize a free-form version string against installed versions. */
    private function normalizePhpVersion(string $version): string
    {
        $id = \Modules\Core\Services\PhpVersionsService::id($version);
        $installed = app(\Modules\Core\Services\PhpVersionsService::class)->installed();
        if (isset($installed[$id])) {
            return $id === '84' ? '8.4' : (substr($id, 0, 1) . '.' . substr($id, 1, 1));
        }
        return '8.4';
    }

    /** Ensure the account's pooled PHP pool for a version (settings applied). */
    private function ensurePhpPool(array $account, string $version, ?array $settings = null): void
    {
        app(\Modules\Core\Services\PhpVersionsService::class)->ensurePool($account, $version, $settings);
    }

    public function phpOverview(): Response
    {
        $websites = app('db')->select(
            'SELECT w.*, d.`name` AS domain_name FROM `websites` w
             LEFT JOIN `domains` d ON d.`id` = w.`domain_id`
             WHERE w.`account_id` = ? ORDER BY w.`label`',
            [$this->accountId()]
        );
        $content = view('customer/websites/php-overview', [
            'title' => 'PHP Versions',
            'websites' => $websites,
        ], 'layouts/customer');

        return new Response((string) $content);
    }

    public function phpSettings(int $id): Response
    {
        $website = app('db')->selectOne('SELECT w.*, d.`name` AS domain_name FROM `websites` w LEFT JOIN `domains` d ON d.`id`=w.`domain_id` WHERE w.`id`=? AND w.`account_id`=?', [$id, $this->accountId()]);
        if (!$website) return Response::redirect('/panel/websites');
        $phpVersions = app(\Modules\Core\Services\PhpVersionsService::class)->installed();
        $settings = json_decode((string) ($website['php_settings'] ?? '{}'), true) ?: [];
        $content = view('customer/websites/php', [
            'title' => 'PHP Settings: ' . $website['label'],
            'website' => $website,
            'phpVersions' => $phpVersions,
            'options' => \Modules\Core\Services\PhpVersionsService::OPTIONS,
            'settings' => $settings,
            'phpDirs' => app(\Modules\Websites\Services\PhpDirService::class)->forWebsite($id),
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function updatePhpSettings(int $id, Request $request): Response
    {
        $accountId = $this->accountId();
        $website = app('db')->selectOne('SELECT * FROM `websites` WHERE `id`=? AND `account_id`=?', [$id, $accountId]);
        if (!$website) return Response::redirect('/panel/websites');

        $phpVersion = $this->normalizePhpVersion((string) $request->input('php_version', '8.4'));
        $allowed = \Modules\Core\Services\PhpVersionsService::OPTIONS;
        $settings = [];
        foreach ($allowed as $key => [$label, $type, $default]) {
            $val = (string) $request->input($key, $default);
            if ($type === 'onoff') {
                $settings[$key] = $val === '1' ? '1' : '0';
            } else {
                $settings[$key] = preg_replace('/[^A-Za-z0-9_.-]/', '', $val);
                if ($settings[$key] === '') $settings[$key] = $default;
            }
        }

        $oldVersion = (string) ($website['php_version'] ?? '8.4');
        app('db')->update('websites', [
            'php_version' => $phpVersion,
            'php_settings' => json_encode($settings),
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);
        $website = app('db')->selectOne('SELECT * FROM `websites` WHERE `id`=?', [$id]);
        $domain = app('db')->selectOne('SELECT * FROM `domains` WHERE `id`=?', [(int) $website['domain_id']]);
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id`=?', [(int) $website['account_id']]);

        // Re-provision pool + regenerate vhost for the (possibly) new version.
        // On pool failure revert the DB row so the site keeps serving the old
        // version instead of pointing at a missing socket.
        try {
            $this->ensurePhpPool($account, $phpVersion, $settings);
        } catch (\Throwable $e) {
            app('db')->update('websites', [
                'php_version' => $oldVersion,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$id]);
            app('logger')->warning('PHP pool provision failed: ' . $e->getMessage());
            app('session')->flash('error', 'PHP version not changed (pool provisioning failed): ' . $e->getMessage());
            return Response::redirect('/panel/websites/' . $id . '/php');
        }
        if (\Modules\Core\Services\PhpVersionsService::id($phpVersion) !== \Modules\Core\Services\PhpVersionsService::id($oldVersion)) {
            app(\Modules\Core\Services\PhpVersionsService::class)->pruneVersion($account, $oldVersion);
        }
        $nginx = app(NginxConfigGenerator::class)->generate($website, $domain, $account);
        app('db')->update('websites', ['nginx_config' => $nginx], '`id` = ?', [$id]);
        try { app(\Modules\Core\Services\NginxIsolationService::class)->pushVhost($account, $domain['name'], $nginx); } catch (\Throwable $e) { app('logger')->warning('PHP vhost push failed: ' . $e->getMessage()); }

        audit('website.php.updated', 'website', $id, ['php_version' => $phpVersion]);
        app('session')->flash('success', 'PHP settings updated.');
        return Response::redirect('/panel/websites/' . $id . '/php');
    }

    /**
     * Save a per-directory PHP override (version and/or locked options).
     * The service provisions the pool + subdir, persists, and pushes the
     * vhost; anything failing there throws before we flash success.
     */
    public function phpDirSave(int $id, Request $request): Response
    {
        $website = app('db')->selectOne('SELECT * FROM `websites` WHERE `id`=? AND `account_id`=?', [$id, $this->accountId()]);
        if (!$website) return Response::redirect('/panel/websites');
        $dir = (string) $request->input('dir', '');
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
            app(\Modules\Websites\Services\PhpDirService::class)->save($id, $dir, $version, $settings);
            app('session')->flash('success', 'Directory override saved and applied.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Directory override not saved: ' . $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id . '/php');
    }

    public function phpDirDelete(int $id, Request $request): Response
    {
        $website = app('db')->selectOne('SELECT * FROM `websites` WHERE `id`=? AND `account_id`=?', [$id, $this->accountId()]);
        if (!$website) return Response::redirect('/panel/websites');
        try {
            app(\Modules\Websites\Services\PhpDirService::class)->delete($id, (int) $request->input('dir_id', 0));
            app('session')->flash('success', 'Directory override removed — the directory follows the site again.');
        } catch (\Throwable $e) {
            app('session')->flash('error', 'Override not removed: ' . $e->getMessage());
        }
        return Response::redirect('/panel/websites/' . $id . '/php');
    }
}
