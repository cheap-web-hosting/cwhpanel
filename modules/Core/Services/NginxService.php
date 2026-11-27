<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * Standalone Nginx admin helpers: run `nginx -t`, reload, and list generated
 * hosting vhosts. All privileged work is delegated to the agent.
 */
class NginxService
{
    /**
     * Run `nginx -t` and return the combined output. Never throws; returns the
     * agent error message on failure to surface it to the admin.
     */
    public function test(): string
    {
        $result = app('provisioning')->execute('nginx.test');
        return trim((string) ($result['output'] ?? ($result['status'] ?? 'unknown')));
    }

    public function reload(): bool
    {
        $result = app('provisioning')->execute('nginx.reload');
        return $result['status'] === 'success';
    }

    /**
     * @return array<int, array{name:string, size:int, modified:string}>
     */
    public function vhosts(): array
    {
        $result = app('provisioning')->execute('nginx.vhosts.list');
        $output = (string) ($result['output'] ?? '');

        $rows = [];
        foreach (explode("\n", trim($output)) as $line) {
            // ls -la output: perms links owner group size month day time name
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 9 || $line === '' || $line[0] === 't') {
                continue;
            }
            $name = implode(' ', array_slice($parts, 8));
            if ($name === '.' || $name === '..' || !str_ends_with($name, '.conf')) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'size' => (int) ($parts[4] ?? 0),
                'modified' => ($parts[5] ?? '') . ' ' . ($parts[6] ?? '') . ' ' . ($parts[7] ?? ''),
            ];
        }

        return $rows;
    }
}