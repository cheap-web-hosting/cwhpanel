<?php

declare(strict_types=1);

namespace Modules\Websites\Services;

use Modules\Core\Services\PhpVersionsService;

/**
 * Per-directory PHP overrides for a website.
 *
 * A subdirectory can run a different PHP version (the vhost gains a
 * `location ^~ /dir/` block proxying to that version's socket) and/or
 * locked per-directory options (fastcgi PHP_ADMIN_VALUE in the same
 * block). NULL version/settings inherit the site — such rows render
 * nothing and exist only as explicit placeholders.
 *
 * Why nginx locations and not .user.ini: the per-account masters bake
 * site options as php_admin_* (package limits must not be raisable from
 * inside the docroot), and php_admin_* cannot be overridden by .user.ini
 * at all — .user.ini files for these keys would be silently dead, while
 * switching the pools to php_value_* would open a limit bypass. The
 * location + PHP_ADMIN_VALUE pairing keeps every override locked and
 * visible in the generated vhost (isolation-aware via the normal push).
 *
 * Inheritance: dir settings override single keys over the pool (which
 * already carries the site options); unset keys fall through. The longest
 * nginx prefix wins between nested dirs automatically (^~ semantics).
 */
class PhpDirService
{
    /** Relative dir charset: safe in nginx prefix locations and on disk. */
    public function normalizeDir(string $dir): string
    {
        $dir = trim(trim($dir), '/');
        if ($dir === '' || strlen($dir) > 255
            || preg_match('#^[A-Za-z0-9_.][A-Za-z0-9_.\-/]*$#', $dir) !== 1
            || str_contains($dir, '..')
            || preg_match('#(^|/)\.($|/)#', $dir) === 1
        ) {
            throw new \RuntimeException('Invalid directory (relative path like blog or shop/app).');
        }
        foreach (explode('/', $dir) as $seg) {
            // Dot-first segments would 403 under the block's own dotfiles
            // deny — reject with a clear error instead of a mystery 403.
            if ($seg === '' || $seg === '.' || $seg === '..' || str_starts_with($seg, '.')) {
                throw new \RuntimeException('Invalid directory (relative path like blog or shop/app).');
            }
        }
        return $dir;
    }

    /**
     * Normalize a version to its two-digit id, or null for inherit.
     * Only installed versions are routable (the socket must exist).
     */
    public function normalizeVersion(?string $version): ?string
    {
        $version = strtolower(trim((string) $version));
        if ($version === '' || $version === 'inherit') {
            return null;
        }
        $id = PhpVersionsService::id($version);
        $installed = app(PhpVersionsService::class)->installed();
        if (!isset($installed[$id])) {
            throw new \RuntimeException('PHP version is not installed.');
        }
        return $id;
    }

    /**
     * Sanitize per-dir options with inherit semantics: blank/missing means
     * "fall through to the pool", never "reset to default" (unlike the
     * site form, which always submits full values).
     *
     * @return array<string,string>
     */
    public function normalizeSettings(array $input): array
    {
        $allowed = PhpVersionsService::OPTIONS;
        $out = [];
        foreach ($allowed as $key => [$label, $type, $default]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $val = (string) $input[$key];
            if ($type === 'onoff') {
                if ($val === '1') {
                    $out[$key] = '1';
                } elseif ($val === '0') {
                    $out[$key] = '0';
                }
                continue;
            }
            $val = preg_replace('/[^A-Za-z0-9_.-]/', '', $val);
            if ($val !== '') {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    /** @return array<int, array> rows for a website, dirs sorted */
    public function forWebsite(int $websiteId): array
    {
        return app('db')->select(
            'SELECT * FROM `website_php_dirs` WHERE `website_id` = ? ORDER BY `dir`',
            [$websiteId]
        );
    }

    /**
     * Create/replace a directory override. Provisions the target version
     * pool (with the site's own options, same rule as a site version
     * switch) and the docroot subdirectory BEFORE persisting, then pushes
     * the vhost — a provisioning failure leaves no row behind.
     */
    public function save(int $websiteId, string $dir, ?string $version, array $settings, bool $asAdmin = false): int
    {
        $website = app('db')->selectOne('SELECT * FROM `websites` WHERE `id` = ?', [$websiteId]);
        if ($website === null) {
            throw new \RuntimeException('Website not found.');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $website['account_id']]);
        if ($account === null) {
            throw new \RuntimeException('Website has no account.');
        }
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'websites', $asAdmin);
        $dir = $this->normalizeDir($dir);
        $versionId = $this->normalizeVersion($version);
        $settings = $this->normalizeSettings($settings);
        if ($versionId === null && $settings === []) {
            throw new \RuntimeException('Nothing to override (version inherits and no options set).');
        }
        if ($versionId !== null && $versionId === PhpVersionsService::id((string) ($website['php_version'] ?? '8.4'))) {
            $versionId = null; // same as the site: inherit, don't pin.
        }

        $docroot = rtrim((string) ($website['document_root'] ?? ''), '/');
        if ($docroot === '') {
            throw new \RuntimeException('Website has no document root.');
        }
        $username = (string) $account['username'];
        $prov = app('provisioning');
        $prov->execute('directory.create', ['path' => $docroot . '/' . $dir]);
        $prov->execute('directory.chown', ['owner' => $username, 'path' => $docroot . '/' . $dir]);
        if ($versionId !== null) {
            $siteSettings = json_decode((string) ($website['php_settings'] ?? '{}'), true) ?: [];
            app(PhpVersionsService::class)->ensurePool($account, $versionId, $siteSettings);
        }

        $now = date('Y-m-d H:i:s');
        $existing = app('db')->selectOne(
            'SELECT * FROM `website_php_dirs` WHERE `website_id` = ? AND `dir` = ?',
            [$websiteId, $dir]
        );
        $payload = [
            'php_version' => $versionId,
            'settings' => $settings === [] ? null : json_encode($settings),
            'updated_at' => $now,
        ];
        if ($existing !== null) {
            app('db')->update('website_php_dirs', $payload, '`id` = ?', [(int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = (int) app('db')->insert('website_php_dirs', [
                'website_id' => $websiteId, 'dir' => $dir,
                'php_version' => $payload['php_version'], 'settings' => $payload['settings'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        try {
            app(PrivacyService::class)->regenerate((int) $websiteId);
        } catch (\Throwable $e) {
            // Roll back: a failed vhost push must not leave a row behind
            // that the running config doesn't serve (bitten live — a
            // broken generator left a stranded nc-cron pin that confused
            // every later install on the site).
            if ($existing !== null) {
                app('db')->update('website_php_dirs', [
                    'php_version' => $existing['php_version'],
                    'settings' => $existing['settings'],
                    'updated_at' => $now,
                ], '`id` = ?', [$id]);
            } else {
                app('db')->delete('website_php_dirs', '`id` = ?', [$id]);
            }
            throw $e;
        }
        audit('website.phpdir.saved', 'website', $websiteId, ['dir' => $dir, 'version' => $versionId]);
        return $id;
    }

    /** Delete an override (scoped to the website) and push the vhost. */
    public function delete(int $websiteId, int $dirId, bool $asAdmin = false): void
    {
        $row = app('db')->selectOne(
            'SELECT * FROM `website_php_dirs` WHERE `id` = ? AND `website_id` = ?',
            [$dirId, $websiteId]
        );
        if ($row === null) {
            throw new \RuntimeException('Directory override not found.');
        }
        $website = app('db')->selectOne('SELECT * FROM `websites` WHERE `id` = ?', [$websiteId]);
        $account = $website !== null
            ? app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $website['account_id']])
            : null;
        if ($account !== null) {
            app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'websites', $asAdmin);
        }
        app('db')->delete('website_php_dirs', '`id` = ?', [$dirId]);
        // Files stay on disk (same convention as app rows); only routing
        // reverts to the site version.
        app(PrivacyService::class)->regenerate($websiteId);
        audit('website.phpdir.deleted', 'website', $websiteId, ['dir' => $row['dir']]);
    }

    /**
     * Rows resolved for the vhost generator: version_id (site id when
     * inheriting) + decoded settings. Fully-inherited rows are dropped —
     * they render nothing.
     *
     * @return array<int, array{dir:string, version_id:string, settings:array}>
     */
    public function effectiveFor(array $website): array
    {
        $websiteId = (int) ($website['id'] ?? 0);
        if ($websiteId <= 0) {
            return [];
        }
        $siteId = PhpVersionsService::id((string) ($website['php_version'] ?? '8.4'));
        $out = [];
        foreach ($this->forWebsite($websiteId) as $row) {
            $versionId = ($row['php_version'] ?? null) !== null && $row['php_version'] !== ''
                ? PhpVersionsService::id((string) $row['php_version'])
                : $siteId;
            if (!preg_match('/^\d{2}$/', $versionId) || !isset(PhpVersionsService::AVAILABLE[$versionId])) {
                continue; // set before validation existed, or version removed: skip, never 502.
            }
            $settings = json_decode((string) ($row['settings'] ?? ''), true) ?: [];
            if ($versionId === $siteId && $settings === []) {
                continue;
            }
            $out[] = ['dir' => (string) $row['dir'], 'version_id' => $versionId, 'settings' => $settings];
        }
        return $out;
    }
}
