<?php
declare(strict_types=1);
namespace Modules\Core\Services;

/**
 * Canonical panel version source.
 *
 * The panel's single source of truth is the VERSION file at the repo root
 * (copied verbatim to the deployment by rsync). A git tag v<version> should
 * be cut on every release; the service also reports the short commit hash
 * when the deployment is a git checkout (dev) — production deployments run
 * from the rsync'd copy, where the commit hash is baked into the version
 * snapshot at install time (see PanelUpdateService::snapshot()).
 */
class PanelVersionService
{
    public const VERSION_FILE = 'VERSION';

    public const GIT_TAG_PREFIX = 'v';

    /** @var string|null */
    private $cached;

    public function current(): string
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $file = BASE_PATH . '/' . self::VERSION_FILE;
        $version = trim((string) @file_get_contents($file));
        if ($version === '' || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            $version = '0.0.0-dev';
        }
        return $this->cached = $version;
    }

    /** Short git commit hash (dev checkout) or null when unavailable. */
    public function commit(): ?string
    {
        $head = BASE_PATH . '/.git/HEAD';
        $raw = @file_get_contents($head);
        if ($raw === false) {
            return null;
        }
        if (preg_match('/^ref:\s+refs\/heads\/(.+)$/m', $raw, $m)) {
            $ref = BASE_PATH . '/.git/refs/heads/' . trim($m[1]);
            $sha = trim((string) @file_get_contents($ref));
            if (preg_match('/^[0-9a-f]{40}$/', $sha)) {
                return substr($sha, 0, 8);
            }
        }
        $sha = trim($raw);
        return preg_match('/^[0-9a-f]{40}$/', $sha) ? substr($sha, 0, 8) : null;
    }

    /** Compare two semver strings ("v" prefixes tolerated). Returns -1/0/1. */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::stripV($a), self::stripV($b));
    }

    public static function stripV(string $v): string
    {
        return preg_replace('/^v/i', '', trim($v));
    }
}
