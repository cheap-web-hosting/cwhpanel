<?php
declare(strict_types=1);
namespace Modules\Core\Services;

/**
 * Panel self-update source-of-truth.
 *
 * Checks the canonical git remote (origin) for the latest release tag and
 * compares it against the installed VERSION. The result is cached in the
 * `settings` table (system.panel_update_status) so the admin Updates page
 * renders without a live GitHub round-trip every time.
 *
 * This is the *check* half of update automation — applying an update (git
 * fetch + rsync + migrate + reload) is deliberately NOT wired here yet; the
 * status snapshot is the contract a future updater consumes.
 */
class PanelUpdateService
{
    private const STATUS_KEY = 'system.panel_update_status';

    private const MODE_KEY = 'system.panel_update_mode';

    public const MODES = ['off', 'notify', 'auto'];

    /** @var PanelVersionService */
    private $versions;

    public function __construct(?PanelVersionService $versions = null)
    {
        $this->versions = $versions ?? app(PanelVersionService::class);
    }

    /** Repo describe: owner/repo from the git remote (or config override). */
    public function remote(): string
    {
        $configured = (string) config('panel.upstream_repo', '');
        if (trim($configured) !== '') {
            return $this->normalizeRepo($configured);
        }
        // Prefer the actual git remote (dev checkouts) so forks follow along.
        $origin = @file_get_contents(BASE_PATH . '/.git/config');
        if ($origin !== false && preg_match('#url\s*=\s*.*?github\.com[:/]([^/\s]+/[^/\s]+?)(?:\.git)?\s*$#m', $origin, $m)) {
            return $m[1];
        }
        return 'cheap-web-hosting/cwhpanel';
    }

    /**
     * Accept "owner/repo" or a full GitHub clone URL and return "owner/repo"
     * (the form the GitHub API needs). Config files are friendlier with the
     * real clone URL, so normalise instead of rejecting it.
     */
    private function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if (preg_match('#github\.com[:/]+([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1] . '/' . $m[2];
        }
        return trim($value, " \t\n\r/");
    }

    /**
     * Latest released version from the GitHub releases API (newest semver
     * tag). Falls back to the tags API when no formal Release has been
     * published (a bare git tag is the version-control baseline). Returns
     * null on any failure — the call is best-effort and must never break
     * the admin page.
     */
    public function latestAvailable(int $timeoutMs = 8000): ?string
    {
        $repo = $this->remote();

        $version = $this->latestFromApi(
            "https://api.github.com/repos/{$repo}/releases?per_page=20",
            $timeoutMs
        );
        if ($version !== null) {
            return $version;
        }

        // No published releases — fall back to the tags listing.
        return $this->latestFromApi(
            "https://api.github.com/repos/{$repo}/tags?per_page=20",
            $timeoutMs
        );
    }

    /** @return string|null newest semver version seen in an API listing */
    private function latestFromApi(string $url, int $timeoutMs): ?string
    {
        $payload = $this->httpGet($url, $timeoutMs);
        if ($payload === null) {
            return null;
        }
        $items = json_decode($payload, true);
        if (!is_array($items)) {
            return null;
        }
        $best = null;
        foreach ($items as $item) {
            $tag = (string) ($item['tag_name'] ?? $item['name'] ?? '');
            $version = PanelVersionService::stripV($tag);
            if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
                continue;
            }
            if ($best === null || version_compare($version, $best, '>')) {
                $best = $version;
            }
        }
        return $best;
    }

    /** Fresh snapshot of installed vs latest (used by the admin page + future updater). */
    public function snapshot(bool $refresh = false): array
    {
        $installed = $this->versions->current();
        $cached = $this->status();

        if ($refresh || $cached === null || (time() - (int) ($cached['checked_at'] ?? 0)) > 3600) {
            $latest = $this->latestAvailable();
            $cached = [
                'installed' => $installed,
                'latest' => $latest,
                'up_to_date' => $latest === null ? null : PanelVersionService::compare($installed, $latest) >= 0,
                'checked_at' => time(),
                'remote' => $this->remote(),
            ];
            $this->putStatus($cached);
        }

        return $cached;
    }

    /** @return array<string,mixed>|null */
    public function status(): ?array
    {
        $raw = null;
        try {
            $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', [self::STATUS_KEY]);
            $raw = $row['value'] ?? null;
        } catch (\Throwable $e) {
        }
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $snapshot */
    private function putStatus(array $snapshot): void
    {
        $value = json_encode($snapshot);
        try {
            $db = app('db');
            if ($db->selectOne('SELECT `key` FROM `settings` WHERE `key` = ?', [self::STATUS_KEY])) {
                $db->update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`key` = ?', [self::STATUS_KEY]);
            } else {
                $db->insert('settings', ['key' => self::STATUS_KEY, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
            }
        } catch (\Throwable $e) {
            try { app('logger')->warning('PanelUpdateService::putStatus failed: ' . $e->getMessage()); } catch (\Throwable) {}
        }
    }

    /** Panel auto-update mode: off | notify (default) | auto. */
    public function mode(): string
    {
        $raw = null;
        try {
            $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', [self::MODE_KEY]);
            $raw = $row['value'] ?? null;
        } catch (\Throwable $e) {
        }
        $mode = in_array($raw, self::MODES, true) ? $raw : 'notify';
        return $mode;
    }

    public function setMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \RuntimeException('Invalid auto-update mode: ' . $mode);
        }
        $value = $mode;
        try {
            $db = app('db');
            if ($db->selectOne('SELECT `key` FROM `settings` WHERE `key` = ?', [self::MODE_KEY])) {
                $db->update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`key` = ?', [self::MODE_KEY]);
            } else {
                $db->insert('settings', ['key' => self::MODE_KEY, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not persist auto-update mode: ' . $e->getMessage());
        }
    }

    /**
     * Download + verify the release assets for a version (shared by apply()
     * and the auto-update check). Fetches the SIGNED runtime bundle:
     *   cwhpanel-<v>-runtime.tar.gz + .sha256 + .sha256.asc + .asc
     * and verifies the clearsigned digest + detached signature against the
     * pinned release key BEFORE returning. Any missing/unsigned/invalid
     * asset throws (fail-closed — nothing installs unverified).
     *
     * @return array{0:string,1:string} [tarball path, sha256 hex]
     */
    public function fetchArchive(string $version): array
    {
        $version = PanelVersionService::stripV($version);
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new \RuntimeException('Invalid target version: ' . $version);
        }
        $repo = $this->remote();
        $updatesDir = '/var/lib/cwhpanel/updates';
        if (!is_dir($updatesDir) && !@mkdir($updatesDir, 0750, true)) {
            throw new \RuntimeException('Cannot create update staging dir ' . $updatesDir);
        }
        $base = $updatesDir . '/cwhpanel-' . $version . '-runtime';

        // Resolve release assets via the API (private repos 404 the web
        // download path; the API asset URL 302s to a signed object store).
        $release = $this->httpGet("https://api.github.com/repos/{$repo}/releases/tags/v{$version}", 120000);
        $assets = [];
        if ($release !== null) {
            $decoded = json_decode($release, true);
            if (is_array($decoded) && isset($decoded['assets']) && is_array($decoded['assets'])) {
                foreach ($decoded['assets'] as $asset) {
                    $assets[(string) ($asset['name'] ?? '')] = (string) ($asset['url'] ?? '');
                }
            }
        }
        foreach (['.tar.gz', '.tar.gz.sha256', '.tar.gz.sha256.asc', '.tar.gz.asc'] as $suffix) {
            $assetName = 'cwhpanel-' . $version . '-runtime' . $suffix;
            $assetUrl = $assets[$assetName] ?? null;
            if ($assetUrl === null) {
                throw new \RuntimeException("Missing signed release asset {$assetName} for v{$version} (release assets not published?)");
            }
            $bytes = $this->httpGetBinaryRaw($assetUrl, 120000, 'application/octet-stream');
            if ($bytes === null) {
                throw new \RuntimeException('Failed to download release asset ' . $assetName);
            }
            if (file_put_contents($base . $suffix, $bytes) === false) {
                throw new \RuntimeException('Failed to write asset ' . $base . $suffix);
            }
        }

        // Verify against the pinned release key (script shares the key pin).
        $verify = BASE_PATH . '/scripts/verify-release.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($verify)
            . ' ' . escapeshellarg($version) . ' ' . escapeshellarg($base . '.tar.gz');
        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException('Signature verification failed for v' . $version . ': ' . implode("\n", $out));
        }

        $line = trim((string) @file_get_contents($base . '.tar.gz.sha256'));
        if (!preg_match('/^([0-9a-f]{64})/', $line, $m)) {
            throw new \RuntimeException('Verified digest file malformed for v' . $version);
        }
        $actual = hash_file('sha256', $base . '.tar.gz');
        if ($actual === false || strtolower($actual) !== strtolower($m[1])) {
            throw new \RuntimeException('Post-verify digest mismatch for v' . $version);
        }

        return [$base . '.tar.gz', strtolower($m[1])];
    }

    /**
     * Apply a panel update to the given version (must be newer than
     * installed). Downloads the release tarball from GitHub (authenticated
     * with PANEL_GITHUB_TOKEN), computes its sha256, and hands it to the
     * agent op `panel.update.apply` which verifies, backs up, swaps,
     * migrates, reloads and smoke-tests with automatic rollback.
     *
     * Returns the agent result summary. Throws on any panel-side failure.
     */
    public function apply(string $version): array
    {
        $version = PanelVersionService::stripV($version);
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new \RuntimeException('Invalid target version: ' . $version);
        }
        $installed = $this->versions->current();
        if (PanelVersionService::compare($installed, $version) >= 0) {
            throw new \RuntimeException("No update: installed v{$installed} is not older than v{$version}.");
        }

        [$archive, $sha256] = $this->fetchArchive($version);

        $result = app('provisioning')->execute('panel.update.apply', [
            'version' => $version,
            'archive' => $archive,
            'sha256' => $sha256,
            'target' => BASE_PATH,
        ]);

        // Refresh the cached status now that we have moved.
        $this->putStatus([
            'installed' => $version,
            'latest' => $version,
            'up_to_date' => true,
            'checked_at' => time(),
            'remote' => $this->remote(),
        ]);

        return $result;
    }

    /** Migration filenames (basenames) present inside a release tarball. */
    public function releaseMigrationFilenames(string $archive): array
    {
        $list = [];
        $cmd = 'tar -tzf ' . escapeshellarg($archive) . ' 2>/dev/null | grep -E "Migrations/.*\\.php$"';
        exec($cmd, $lines);
        foreach ($lines as $line) {
            $base = basename(trim($line));
            if ($base !== '' && $base !== '.') {
                $list[$base] = true;
            }
        }
        return array_keys($list);
    }

    /** Migration filenames (basenames) in the local tree. */
    public function localMigrationFilenames(): array
    {
        $list = [];
        foreach ((array) glob(BASE_PATH . '/modules/*/Migrations/*.php') as $file) {
            $list[basename($file)] = true;
        }
        return array_keys($list);
    }

    /** True when a release ships migrations the local tree does not have. */
    public function hasNewMigrations(string $archive): bool
    {
        $local = array_flip($this->localMigrationFilenames());
        foreach ($this->releaseMigrationFilenames($archive) as $base) {
            if (!isset($local[$base])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Auto-apply entry point (called by the daily check). Respects the
     * configured mode; only applies when mode=auto, a newer release exists,
     * and the release does not introduce new DB migrations (migration drift
     * after a rollback is the one case that always needs a human).
     *
     * @return array{applied:bool,reason:string,version:?string,detail:?string}
     */
    public function attemptAutoApply(): array
    {
        $mode = $this->mode();
        if ($mode !== 'auto') {
            return ['applied' => false, 'reason' => 'mode', 'version' => null, 'detail' => "mode={$mode}"];
        }

        $snap = $this->snapshot(false);
        $latest = $snap['latest'] ?? null;
        if ($latest === null || !empty($snap['up_to_date'])) {
            return ['applied' => false, 'reason' => 'none', 'version' => $latest, 'detail' => null];
        }

        try {
            [$archive] = $this->fetchArchive($latest);
        } catch (\Throwable $e) {
            return ['applied' => false, 'reason' => 'download_failed', 'version' => $latest, 'detail' => $e->getMessage()];
        }

        if ($this->hasNewMigrations($archive)) {
            try {
                app(\Modules\Core\Services\NotificationService::class)->pushAdmin(
                    'system.panel_update',
                    'Panel update v' . $latest . ' requires manual review',
                    'The release introduces new database migrations — auto-apply was skipped. Apply manually from Server → Updates.',
                    '/admin/server/updates'
                );
            } catch (\Throwable $e) {
            }
            return ['applied' => false, 'reason' => 'migrations', 'version' => $latest, 'detail' => 'release adds migrations; manual apply required'];
        }

        try {
            $result = $this->apply($latest);
            audit('system.panel_update.apply', 'server', null, ['version' => $latest, 'auto' => true, 'result' => $result['output'] ?? null]);
            try {
                app(\Modules\Core\Services\NotificationService::class)->pushAdmin(
                    'system.panel_update',
                    'Panel auto-updated to v' . $latest,
                    trim((string) ($result['output'] ?? 'Auto-update applied successfully.')),
                    '/admin/server/updates'
                );
            } catch (\Throwable $e) {
            }
            return ['applied' => true, 'reason' => 'applied', 'version' => $latest, 'detail' => $result['output'] ?? null];
        } catch (\Throwable $e) {
            audit('system.panel_update.apply', 'server', null, ['version' => $latest, 'auto' => true, 'error' => $e->getMessage()]);
            try {
                app(\Modules\Core\Services\NotificationService::class)->pushAdmin(
                    'system.panel_update',
                    'Panel auto-update to v' . $latest . ' FAILED',
                    $e->getMessage(),
                    '/admin/server/updates'
                );
            } catch (\Throwable $e2) {
            }
            return ['applied' => false, 'reason' => 'apply_failed', 'version' => $latest, 'detail' => $e->getMessage()];
        }
    }

    /** Binary-safe HTTPS GET (curl); null on failure. */
    private function httpGetBinary(string $url, int $timeoutMs): ?string
    {
        return $this->httpGetBinaryRaw($url, $timeoutMs, 'application/vnd.github+json');
    }

    /** Binary-safe HTTPS GET with a custom Accept header (curl); null on failure. */
    private function httpGetBinaryRaw(string $url, int $timeoutMs, string $accept): ?string
    {
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: cwhpanel-update-check/1.0',
        ];
        $token = (string) env('PANEL_GITHUB_TOKEN', '');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_HTTPHEADER => $headers,
                // Explicit TLS verification + HTTPS-only + no cross-origin
                // redirect of the bearer token.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => $token === '',
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            return $errno === 0 && is_string($body) && $body !== '' ? $body : null;
        }
        // No cURL: refuse instead of silently downgrading TLS. The stream
        // fallback used to disable certificate/hostname verification AND
        // forward the Authorization header across redirects; a missing
        // extension must never weaken update transport security.
        app('logger')->error('panel update: php-curl is required for updates; refusing insecure stream fallback');
        return null;
    }

    /** Minimal HTTPS GET (curl if available, else file_get_contents). */
    private function httpGet(string $url, int $timeoutMs): ?string
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: cwhpanel-update-check/1.0',
        ];
        // Private repos need an authenticated GitHub token (PANEL_GITHUB_TOKEN).
        $token = (string) env('PANEL_GITHUB_TOKEN', '');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_HTTPHEADER => $headers,
                // Explicit TLS verification + HTTPS-only + no cross-origin
                // redirect of the bearer token.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => $token === '',
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            return $errno === 0 && is_string($body) && $body !== '' ? $body : null;
        }
        // No cURL: refuse instead of silently downgrading TLS. The stream
        // fallback used to disable certificate/hostname verification AND
        // forward the Authorization header across redirects; a missing
        // extension must never weaken update transport security.
        app('logger')->error('panel update: php-curl is required for updates; refusing insecure stream fallback');
        return null;
    }
}
