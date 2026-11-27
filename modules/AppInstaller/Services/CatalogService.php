<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Services;

/**
 * Reads the application catalog (ai_applications + ai_categories) and decodes
 * each application's declarative manifest (JSON in ai_applications.manifest).
 */
class CatalogService
{
    public function allApplications(): array
    {
        return app('db')->select(
            'SELECT * FROM `ai_applications` WHERE `is_active` = 1 ORDER BY `is_featured` DESC, `sort_order`, `name`'
        );
    }

    public function find(string $slug): ?array
    {
        return app('db')->selectOne('SELECT * FROM `ai_applications` WHERE `slug` = ?', [$slug]) ?: null;
    }

    public function findById(int $id): ?array
    {
        return app('db')->selectOne('SELECT * FROM `ai_applications` WHERE `id` = ?', [$id]) ?: null;
    }

    public function categories(): array
    {
        return app('db')->select('SELECT * FROM `ai_categories` WHERE `is_active` = 1 ORDER BY `sort_order`, `name`');
    }

    /** Decode and return the application manifest array. */
    public function manifest(array $application): array
    {
        $raw = (string) ($application['manifest'] ?? '');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Technology family for filtering/badges: php (php-mysql/php),
     * node (node-npm/node-archive), python (python-pip), binary, other.
     */
    public static function techOf(array $manifest): string
    {
        return match ($manifest['type'] ?? '') {
            'php-mysql', 'php' => 'php',
            'node-npm', 'node-archive' => 'node',
            'python-pip' => 'python',
            'binary' => 'binary',
            default => 'other',
        };
    }

    /** @return array<string,string> tech key => label, in filter order */
    public static function techOptions(): array
    {
        return ['php' => 'PHP', 'node' => 'Node.js', 'python' => 'Python', 'binary' => 'Binary'];
    }

    /**
     * The version to SHOW for an app. Pinned rows show their version;
     * registry installs (pip/npm, version 'latest') show the tracked
     * upstream version; evergreen URLs fall back to 'latest' — never blank.
     * A single leading v (v2.38.2) is stripped for display only.
     */
    public function displayVersion(array $application): string
    {
        $v = trim((string) ($application['version'] ?? ''));
        if ($v !== '' && !in_array(strtolower($v), ['latest', 'stable', 'empty'], true)) {
            return (string) preg_replace('/^[vV]/', '', $v, 1);
        }
        $latest = trim((string) ($application['latest_version'] ?? ''));
        if ($latest !== '') {
            return (string) preg_replace('/^[vV]/', '', $latest, 1);
        }
        return 'latest';
    }

    /** Applications belonging to a category slug. */
    public function byCategory(string $slug): array
    {
        return app('db')->select(
            'SELECT * FROM `ai_applications` WHERE `is_active` = 1 AND `category` = ? ORDER BY `is_featured` DESC, `name`',
            [$slug]
        );
    }
}