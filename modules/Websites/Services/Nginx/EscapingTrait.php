<?php

declare(strict_types=1);

namespace Modules\Websites\Services\Nginx;

/**
 * @internal Extracted from NginxConfigGenerator (concern group).
 */
trait EscapingTrait
{


    private function escapeAuthTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title));
        if ($title === '' || strlen($title) > 128 || str_contains($title, '"') || str_contains($title, "\n")) {
            throw new \RuntimeException('Invalid auth title.');
        }
        return $title;
    }


    private function isWafEnabled(array $website): bool
    {
        $websiteId = (int) ($website['id'] ?? 0);
        if ($websiteId <= 0) {
            return false;
        }

        $row = app('db')->selectOne(
            'SELECT `waf_enabled` FROM `security_settings` WHERE `website_id` = ?',
            [$websiteId]
        );

        if ($row === null) {
            // No settings row yet (new/legacy site): follow the global default.
            return app(\Modules\Security\Services\WafService::class)->defaultEnabled();
        }

        return (bool) $row['waf_enabled'];
    }


    private function escapeServerName(string $name): string
    {
        $name = strtolower(trim($name));

        if (!preg_match('/^[a-z0-9.-]+$/', $name)) {
            throw new \RuntimeException("Invalid server_name [{$name}].");
        }

        return $name;
    }


    private function escapePath(string $path): string
    {
        $path = trim($path);

        if (str_contains($path, '..') || str_contains($path, "\n") || str_contains($path, ';')) {
            throw new \RuntimeException("Invalid path [{$path}].");
        }

        return $path;
    }


    private function escapeIdentifier(string $value): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,30}$/', strtolower($value))) {
            throw new \RuntimeException("Invalid identifier [{$value}].");
        }

        return strtolower($value);
    }
}
