<?php

declare(strict_types=1);

namespace Modules\Websites\Services;

/**
 * Persists generated Nginx vhost configs via the privileged agent.
 *
 * The agent performs: atomic write -> `nginx -t` -> reload -> rollback on
 * failure. When provisioning is disabled the call is recorded as a dry-run.
 */
class VhostWriter
{
    /**
     * @param bool $deferReload write + `nginx -t` only; the caller coalesces
     *                          the global reload (customer WAF changes, audit #23)
     */
    public function write(string $name, string $contents, bool $deferReload = false): array
    {
        try {
            app('provisioning')->execute('nginx.logs.touch', ['name' => $name]);
        } catch (\Throwable $e) {
            app('logger')->warning('Nginx log pre-create failed for ' . $name . ': ' . $e->getMessage());
        }
        $args = [
            'name' => $name,
            'contents' => $contents,
        ];
        if ($deferReload) {
            $args['defer_reload'] = '1';
        }
        return app('provisioning')->execute('nginx.vhost.write', $args);
    }

    public function delete(string $name): array
    {
        return app('provisioning')->execute('nginx.vhost.delete', [
            'name' => $name,
        ]);
    }
}
