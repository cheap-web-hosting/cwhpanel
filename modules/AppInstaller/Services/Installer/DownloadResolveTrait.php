<?php

declare(strict_types=1);

namespace Modules\AppInstaller\Services\Installer;

/**
 * @internal Extracted from InstallerService (concern group).
 */
trait DownloadResolveTrait
{


    /**
     * Shared archive fetch+extract used by php installs (docroot) and
     * node-archive installs (app work dir). Returns when done.
     */
    private function installArchive(int $installationId, array $account, array $application, array $manifest, string $destAbs, string $home): void
    {
        $username = $account['username'];
        $dl = $manifest['download'] ?? [];
        $url = $this->resolveDownloadUrl($application, $manifest);
        $sha = (string) ($dl['sha256'] ?? '');
        $format = ($dl['format'] ?? 'tar') === 'zip' ? 'zip' : 'tar';
        $ext = $format === 'zip' ? 'zip' : 'tar.gz';
        if ($url === '') {
            throw new \RuntimeException('Application has no download source.');
        }
        $this->log($installationId, 'info', "Downloading {$url}");
        $archive = $this->download->download($url, $sha, $ext);
        $destRel = ltrim(str_replace($home, '', $destAbs), '/');
        if ($destRel === '' || str_starts_with($destRel, '..')) {
            $this->download->cleanup($archive);
            throw new \RuntimeException('Install destination escapes account home.');
        }
        $this->log($installationId, 'info', "Extracting to {$destAbs}");
        app('provisioning')->execute('installer.install', [
            'account' => $username,
            'archive' => $archive,
            'dest' => $destRel,
            'format' => $format,
            'strip_root' => !empty($dl['strip_root']) ? '1' : '0',
        ]);
        $this->download->cleanup($archive);
    }


    /**
     * Resolve the download URL for a manifest. Three shapes, in order:
     *   1. download.url — static evergreen URL, OR a version-templated URL
     *      containing {version} (bare, e.g. 1.43.0) / {tag} (with prefix,
     *      e.g. v1.43.0) expanded from the catalog row's version. This lets
     *      versioned upstream URLs (MediaWiki, Moodle…) auto-follow the
     *      weekly catalog refresh exactly like github-templated rows.
     *   2. download.github {repo, asset, tag_prefix?} — release asset URL
     *      built from the catalog version (see resolveGithubDownload).
     *   3. download.codeberg — same shape for Codeberg/Forgejo releases.
     */
    public function resolveDownloadUrl(array $application, array $manifest): string
    {
        $dl = $manifest['download'] ?? [];
        $url = (string) ($dl['url'] ?? '');
        if ($url !== '') {
            if (str_contains($url, '{version}') || str_contains($url, '{tag}')) {
                $version = (string) ($application['version'] ?? '');
                if ($version === '' || strcasecmp($version, 'latest') === 0 || strcasecmp($version, 'stable') === 0) {
                    throw new \RuntimeException('Version-templated download needs a pinned catalog version.');
                }
                $prefix = array_key_exists('tag_prefix', $dl) ? (string) $dl['tag_prefix'] : 'v';
                $bare = ltrim($version, 'vV');
                $url = str_replace(['{tag}', '{version}'], [$prefix . $bare, $bare], $url);
            }
            if (!preg_match('#^https://#i', $url)) {
                throw new \RuntimeException('Only HTTPS download sources are allowed.');
            }
            return $url;
        }
        if (isset($dl['github'])) {
            return $this->resolveGithubDownload($application, $manifest);
        }
        if (isset($dl['codeberg'])) {
            return $this->resolveCodebergDownload($application, $manifest);
        }
        return '';
    }


    /** True when the manifest's download URL derives from the catalog version. */
    public static function isVersionTemplated(array $manifest): bool
    {
        $dl = $manifest['download'] ?? [];
        if (isset($dl['github']) || isset($dl['codeberg'])) {
            return true;
        }
        $url = (string) ($dl['url'] ?? '');
        return str_contains($url, '{version}') || str_contains($url, '{tag}');
    }


    /**
     * Resolve a templated GitHub download: manifest download.github =
     * {repo, asset, tag_prefix?} where {tag}/{version} placeholders expand
     * from the catalog row (tag keeps its prefix — usually 'v', sometimes
     * '' like Joomla; version always strips it for asset filenames).
     */
    public function resolveGithubDownload(array $application, array $manifest): string
    {
        $gh = $manifest['download']['github'] ?? null;
        if (!is_array($gh) || empty($gh['repo']) || empty($gh['asset'])) {
            return '';
        }
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $gh['repo'])) {
            throw new \RuntimeException('Invalid GitHub repo.');
        }
        $version = (string) ($application['version'] ?? '');
        if ($version === '' || $version === 'latest') {
            throw new \RuntimeException('GitHub-templated download needs a pinned catalog version.');
        }
        $prefix = array_key_exists('tag_prefix', $gh) ? (string) $gh['tag_prefix'] : 'v';
        $bare = ltrim($version, 'vV');
        $tag = $prefix . $bare;
        $asset = str_replace(['{tag}', '{version}'], [$tag, $bare], (string) $gh['asset']);
        if (str_contains($asset, '..') || str_contains($asset, '/')) {
            throw new \RuntimeException('Invalid GitHub asset template.');
        }
        return "https://github.com/{$gh['repo']}/releases/download/{$tag}/{$asset}";
    }


    /**
     * Same shape for Codeberg (Gitea API): download.codeberg =
     * {repo, asset, tag_prefix?}. Forgejo publishes raw binaries there.
     */
    public function resolveCodebergDownload(array $application, array $manifest): string
    {
        $gh = $manifest['download']['codeberg'] ?? null;
        if (!is_array($gh) || empty($gh['repo']) || empty($gh['asset'])) {
            return '';
        }
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $gh['repo'])) {
            throw new \RuntimeException('Invalid Codeberg repo.');
        }
        $version = (string) ($application['version'] ?? '');
        if ($version === '' || $version === 'latest') {
            throw new \RuntimeException('Codeberg-templated download needs a pinned catalog version.');
        }
        $prefix = array_key_exists('tag_prefix', $gh) ? (string) $gh['tag_prefix'] : 'v';
        $bare = ltrim($version, 'vV');
        $tag = $prefix . $bare;
        $asset = str_replace(['{tag}', '{version}'], [$tag, $bare], (string) $gh['asset']);
        if (str_contains($asset, '..') || str_contains($asset, '/')) {
            throw new \RuntimeException('Invalid Codeberg asset template.');
        }
        return "https://codeberg.org/{$gh['repo']}/releases/download/{$tag}/{$asset}";
    }
}
