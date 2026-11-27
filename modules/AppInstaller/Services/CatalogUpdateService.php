<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Services;

/**
 * Catalog freshness: tracks upstream releases per application and
 * auto-updates versioned catalog rows.
 *
 * Check sources (manifest `update_check`):
 *   {"type":"github","repo":"owner/name"} — latest STABLE tag via the
 *     releases Atom feed (pre-release tags skipped, no API quota burn).
 *     Auto-applies: version=tag when the manifest uses a templated
 *     download (github/codeberg block or a {version}/{tag} URL — both
 *     resolve from the version at install time, so refreshed rows install
 *     fresh code). For evergreen-URL rows the check only records
 *     latest_version (shown on cards) — nothing to auto-apply.
 *   {"type":"codeberg","repo":"group/name"} — latest tag via the Codeberg
 *     API. Same auto-apply rule.
 *   {"type":"wordpress"} — WordPress core via api.wordpress.org (its
 *     GitHub tags are not version-shaped). Display only, like github on
 *     evergreen rows.
 *   {"type":"url","url":...,"pattern":...} — first capture of a PCRE
 *     pattern over an HTTPS document (e.g. Matomo's LATEST file).
 *     Display only.
 *   {"type":"pypi","package":"name"} / {"type":"npm","package":"name"} —
 *     registry latest version. Informational for pip/npm app types (their
 *     installs resolve latest at install time anyway); no auto-apply.
 *   missing/static — evergreen URLs, nothing to track.
 *
 * update_available is derived, never stored: latest_version set, non-empty,
 * different from `version`, AND the manifest has a templated download
 * (otherwise there is nothing automatic to apply).
 */
class CatalogUpdateService
{
    public const CHECK_INTERVAL = 86400;

    /** @return array{latest:?string, checked:bool, error:string} */
    public function check(array $application): array
    {
        $manifest = app(CatalogService::class)->manifest($application);
        $uc = $manifest['update_check'] ?? null;
        if (!is_array($uc) || empty($uc['type']) || ($uc['type'] ?? '') === 'static') {
            return ['latest' => null, 'checked' => false, 'error' => ''];
        }
        try {
            $latest = match ($uc['type']) {
                'github' => $this->latestGithub((string) ($uc['repo'] ?? '')),
                'codeberg' => $this->latestCodeberg((string) ($uc['repo'] ?? '')),
                'pypi' => $this->latestPypi((string) ($uc['package'] ?? '')),
                'npm' => $this->latestNpm((string) ($uc['package'] ?? '')),
                'wordpress' => $this->latestWordpress(),
                'url' => $this->latestUrl((string) ($uc['url'] ?? ''), (string) ($uc['pattern'] ?? '')),
                default => throw new \RuntimeException('unknown update source'),
            };
        } catch (\Throwable $e) {
            return ['latest' => null, 'checked' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
        return ['latest' => $latest, 'checked' => true, 'error' => ''];
    }

    /**
     * Refresh due rows (checked_at older than 24h, or all when forced).
     * Returns [checked, updated, failed]. Auto-applies version-templated
     * rows to the new tag; everything else only records latest_version.
     */
    public function refreshAll(bool $force = false, int $limit = 200): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'failed' => 0];
        $rows = app('db')->select('SELECT * FROM `ai_applications` ORDER BY `id`');
        foreach ($rows as $row) {
            if (count($rows) > 0 && $stats['checked'] >= $limit) {
                break;
            }
            $last = (int) ($row['latest_checked_at'] ?? 0);
            if (!$force && $last > 0 && (time() - $last) < self::CHECK_INTERVAL) {
                continue;
            }
            $manifest = app(CatalogService::class)->manifest($row);
            $uc = $manifest['update_check'] ?? null;
            if (!is_array($uc) || empty($uc['type']) || ($uc['type'] ?? '') === 'static') {
                continue;
            }
            $stats['checked']++;
            $res = $this->check($row);
            $patch = ['latest_checked_at' => time(), 'updated_at' => date('Y-m-d H:i:s')];
            if ($res['checked'] && $res['latest'] !== null && $res['latest'] !== '') {
                $patch['latest_version'] = mb_substr($res['latest'], 0, 64);
                // Auto-update: version-templated manifests (github/codeberg
                // blocks or {version}/{tag} URLs) resolve downloads from
                // `version`, so bumping it IS the catalog update. Compare +
                // store the BARE version (strip tag prefixes first).
                $bare = self::stripToVersion($manifest, $res['latest']);
                if (InstallerService::isVersionTemplated($manifest) && $bare !== '' && self::compareVersions((string) ($row['version'] ?? ''), $bare) < 0) {
                    // Never auto-install a prerelease over a stable catalog
                    // pin (Joomla 6.2.0-beta3 over 5.1.0): record it, let a
                    // human apply it from the admin page.
                    if (self::isPrerelease($bare) && !self::isPrerelease((string) ($row['version'] ?? ''))) {
                        // latest_version recorded above; no version bump.
                    } elseif ($this->bumpTargetAlive($manifest, $bare)) {
                        $patch['version'] = mb_substr($bare, 0, 64);
                        $stats['updated']++;
                    }
                    // else: tag exists but its asset is not published yet
                    // (CMSMS v2.2.23): keep the old working version, retry
                    // next sweep.
                }
            } else {
                $stats['failed']++;
            }
            try {
                app('db')->update('ai_applications', $patch, '`id` = ?', [(int) $row['id']]);
            } catch (\Throwable $e) {
                // A single bad row (overlong tag, lost race) must never abort
                // the whole weekly sweep.
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /** Refresh one row now. Returns the check result. */
    public function refreshOne(int $id): array
    {
        $row = app('db')->selectOne('SELECT * FROM `ai_applications` WHERE `id` = ?', [$id]);
        if ($row === null) {
            throw new \RuntimeException('Application not found.');
        }
        $res = $this->check($row);
        $patch = ['latest_checked_at' => time(), 'updated_at' => date('Y-m-d H:i:s')];
        if ($res['checked'] && $res['latest'] !== null && $res['latest'] !== '') {
            $patch['latest_version'] = mb_substr($res['latest'], 0, 64);
            // Same auto-bump rule as the sweep: version-templated manifests
            // track the catalog version forward (stable only, asset alive).
            // Single-row refresh must converge identically to refreshAll —
            // otherwise an admin "Refresh" shows a new number the sweep
            // would never apply, and the card keeps showing 'latest'.
            $manifest = app(CatalogService::class)->manifest($row);
            $bare = self::stripToVersion($manifest, $res['latest']);
            if (InstallerService::isVersionTemplated($manifest)
                && $bare !== ''
                && self::compareVersions((string) ($row['version'] ?? ''), $bare) < 0
                && !(self::isPrerelease($bare) && !self::isPrerelease((string) ($row['version'] ?? '')))
                && $this->bumpTargetAlive($manifest, $bare)
            ) {
                $patch['version'] = mb_substr($bare, 0, 64);
            }
        }
        app('db')->update('ai_applications', $patch, '`id` = ?', [$id]);
        return $res;
    }

    /** Copy latest_version into version (admin "apply update"). */
    public function applyUpdate(int $id): void
    {
        $row = app('db')->selectOne('SELECT * FROM `ai_applications` WHERE `id` = ?', [$id]);
        if ($row === null) {
            throw new \RuntimeException('Application not found.');
        }
        if (empty($row['latest_version'])) {
            throw new \RuntimeException('No upstream version known — refresh first.');
        }
        $manifest = app(CatalogService::class)->manifest($row);
        $bare = mb_substr(self::stripToVersion($manifest, (string) $row['latest_version']), 0, 64);
        if (InstallerService::isVersionTemplated($manifest) && !$this->bumpTargetAlive($manifest, $bare)) {
            throw new \RuntimeException('Upstream asset not published for that tag yet — try again later.');
        }
        app('db')->update('ai_applications', [
            'version' => $bare,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$id]);
    }

    /**
     * Would a bump to $bareVersion actually install? Resolves the download
     * URL the installer would fetch and HEADs it. Guards against tags that
     * exist without published assets (CMSMS v2.2.23) — bumping to those
     * would break every future install of the app.
     */
    private function bumpTargetAlive(array $manifest, string $bareVersion): bool
    {
        try {
            $url = app(InstallerService::class)->resolveDownloadUrl(['version' => $bareVersion], $manifest);
        } catch (\Throwable $e) {
            return false;
        }
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return false;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'cwhpanel-appcatalog/1.0',
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $ok && $code >= 200 && $code < 400;
    }

    /**
     * Upstream checks return TAGS (v2.38.2, mybb_1840) but the catalog
     * `version` stores the BARE form (2.38.2, 1840) — the download resolver
     * re-applies the manifest tag_prefix at install time. Storing the raw
     * tag would double the prefix on the next resolve (mybb_mybb_1840).
     */
    public static function stripToVersion(array $manifest, string $tag): string
    {
        $tag = trim($tag);
        $dl = $manifest['download'] ?? [];
        foreach (['github', 'codeberg'] as $src) {
            $prefix = (string) (($dl[$src]['tag_prefix'] ?? null) ?? 'v');
            if ($prefix !== '' && str_starts_with($tag, $prefix)) {
                $stripped = substr($tag, strlen($prefix));
                if ($stripped !== '') {
                    return $stripped;
                }
            }
        }
        // Default single-v strip (v2.38.2 → 2.38.2); ltrim would eat 'vv…'.
        return (string) preg_replace('/^[vV]/', '', $tag, 1);
    }

    public static function updateAvailable(array $app): bool
    {
        $latest = trim((string) ($app['latest_version'] ?? ''));
        $current = trim((string) ($app['version'] ?? ''));
        if ($latest === '' || $current === '' || strcasecmp($current, 'latest') === 0) {
            return false;
        }
        $manifest = [];
        if (isset($app['manifest'])) {
            $manifest = json_decode((string) $app['manifest'], true) ?: [];
        }
        if (!InstallerService::isVersionTemplated($manifest)) {
            return false;
        }
        // latest_version stores the upstream TAG — compare bare forms.
        return self::compareVersions($current, self::stripToVersion($manifest, $latest)) < 0;
    }

    /**
     * Tolerant version compare: strips leading v, compares dot/dash
     * numeric runs numerically, treats a bare release as newer than a
     * suffixed prerelease (1.2.3 > 1.2.3-beta).
     */
    public static function compareVersions(string $a, string $b): int
    {
        $norm = function (string $v): array {
            $v = ltrim(trim($v), 'vV=');
            $parts = preg_split('/[.\-+_]/', $v) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $out[] = ctype_digit($p) ? ['n', (int) $p] : ['s', strtolower($p)];
            }
            return $out;
        };
        $pa = $norm($a);
        $pb = $norm($b);
        $n = max(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            $xa = $pa[$i] ?? ['n', 0];
            $xb = $pb[$i] ?? ['n', 0];
            if ($xa[0] !== $xb[0]) {
                // Numeric run beats a suffix at the same position, EXCEPT a
                // missing part (treated as 0) loses to nothing... symmetric:
                // compare numbers numerically, strings lexically, and a
                // number sorts after a string (release > prerelease).
                return $xa[0] === 'n' ? 1 : -1;
            }
            if ($xa[1] !== $xb[1]) {
                return $xa[1] < $xb[1] ? -1 : 1;
            }
        }
        return 0;
    }

    /** True when a bare version looks like a pre-release (alpha/beta/rc). */
    public static function isPrerelease(string $v): bool
    {
        // Matches suffixed forms (6.2.0-beta3, 9.2.0-rc.1), +dev builds
        // (ClassicPress 2.7.3+dev tags its staging line that way — the real
        // releases live in a separate -release repo), AND bare rc-digit
        // tags (v35.0.1rc1, 34.0.4rc1 — Nextcloud style, no separator
        // before rc). The trailing-v guard (4.91v is a version, not a
        // prerelease) stays intact.
        return (bool) preg_match('/([-+.](alpha|beta|rc|preview|dev|a|b)[\d.]*|rc\d+|\+dev)$/i', ltrim(trim($v), 'vV'));
    }

    private function latestGithub(string $repo): string
    {
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new \RuntimeException('Invalid GitHub repo.');
        }
        $xml = $this->fetch("https://github.com/{$repo}/releases.atom");
        // Collect tags from entry links (/releases/tag/<tag>): some projects
        // use free-form release titles ("Mautic Community 7.2.0 Lynx
        // Edition") while the link always carries the real tag. Prefer the
        // first STABLE tag — pre-releases often top the feed (Matomo alphas,
        // Joomla betas) and must not become the tracked "latest".
        if (!preg_match_all('#<entry>.*?<link[^>]+href="[^"]*/releases/tag/([^"/]+)"#s', $xml, $m) || empty($m[1])) {
            if (!preg_match_all('#<entry>.*?<title>([^<]+)</title>#s', $xml, $t) || empty($t[1])) {
                throw new \RuntimeException('No releases found.');
            }
            return trim(html_entity_decode($t[1][0]));
        }
        $tags = array_values(array_unique(array_map(
            fn ($t) => trim(urldecode(html_entity_decode($t))),
            $m[1]
        )));
        foreach ($tags as $tag) {
            if ($tag !== '' && !self::isPrerelease($tag)) {
                return $tag;
            }
        }
        if ($tags !== [] && $tags[0] !== '') {
            return $tags[0];
        }
        throw new \RuntimeException('No releases found.');
    }

    /** WordPress core version via the official version-check API. */
    private function latestWordpress(): string
    {
        $data = json_decode($this->fetch('https://api.wordpress.org/core/version-check/1.7/'), true);
        $offer = $data['offers'][0] ?? null;
        $current = is_array($offer) ? trim((string) ($offer['current'] ?? '')) : '';
        if ($current === '' && is_array($offer) && preg_match('/wordpress-([\d.]+)\.zip/i', (string) ($offer['download'] ?? ''), $mm)) {
            $current = $mm[1];
        }
        if ($current === '') {
            throw new \RuntimeException('No version found.');
        }
        return $current;
    }

    /**
     * Generic version source: fetch an HTTPS URL and return the first
     * capture of $pattern (e.g. Matomo's builds.matomo.org/LATEST plain
     * file). Display-only workhorse for upstreams whose release feeds are
     * unusable (all-prerelease pages). Pattern must be a delimited PCRE
     * string with exactly one capture group; the capture is length-capped
     * and charset-checked so a hijacked endpoint cannot smuggle junk in.
     */
    private function latestUrl(string $url, string $pattern): string
    {
        if (!preg_match('#^https://[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.~/%+-]*)?$#', $url)) {
            throw new \RuntimeException('Invalid check URL.');
        }
        if (strlen($pattern) < 4 || strlen($pattern) > 128 || $pattern[0] !== '/' || strrpos($pattern, '/') === 0) {
            throw new \RuntimeException('Invalid check pattern.');
        }
        $body = $this->fetch($url);
        $ok = @preg_match($pattern, $body, $mm);
        if ($ok !== 1 || !isset($mm[1])) {
            throw new \RuntimeException('No version found.');
        }
        $v = trim((string) $mm[1]);
        if ($v === '' || strlen($v) > 32 || !preg_match('/^[A-Za-z0-9_.+-]+$/', $v)) {
            throw new \RuntimeException('No version found.');
        }
        return $v;
    }

    private function latestCodeberg(string $repo): string
    {
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new \RuntimeException('Invalid Codeberg repo.');
        }
        $data = json_decode($this->fetch("https://codeberg.org/api/v1/repos/{$repo}/releases/latest"), true);
        $tag = $data['tag_name'] ?? null;
        if (!is_string($tag) || $tag === '') {
            throw new \RuntimeException('No releases found.');
        }
        return $tag;
    }

    private function latestPypi(string $pkg): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $pkg)) {
            throw new \RuntimeException('Invalid PyPI package.');
        }
        $data = json_decode($this->fetch('https://pypi.org/pypi/' . rawurlencode($pkg) . '/json'), true);
        $v = $data['info']['version'] ?? null;
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException('No version found.');
        }
        return $v;
    }

    private function latestNpm(string $pkg): string
    {
        if (!preg_match('#^(@[a-z0-9~-][a-z0-9~._-]*/)?[a-z0-9~-][a-z0-9~._-]*$#', $pkg)) {
            throw new \RuntimeException('Invalid npm package.');
        }
        $data = json_decode($this->fetch('https://registry.npmjs.org/' . str_replace('/', '%2f', $pkg) . '/latest'), true);
        $v = $data['version'] ?? null;
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException('No version found.');
        }
        return $v;
    }

    private function fetch(string $url): string
    {
        $ctx = stream_context_create(['http' => [
            'timeout' => 15,
            'user_agent' => 'cwhpanel-appcatalog/1.0',
            'follow_location' => 1,
            'max_redirects' => 3,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Upstream unreachable.');
        }
        return $body;
    }
}
