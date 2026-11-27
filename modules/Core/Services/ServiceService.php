<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * Host service management: list running systemd services, query a single
 * service's state, and start/stop/restart allowlisted units via the agent.
 *
 * The authoritative service allowlist lives in the agent (`validate_service`).
 */
class ServiceService
{
    private const DEFAULT_UNITS = [
        'nginx', 'php-fpm', 'mariadb', 'pdns', 'postfix', 'dovecot',
        'vsftpd', 'clamd@scan',
        'clamav-freshclam', 'crond', 'sshd',
        'cwhpanel-agent', 'cwhpanel-worker', 'cwhpanel-mail-policy',
    ];

    /**
     * @return array<int, array{name:string, state:string, active:bool}>
     */
    public function statuses(): array
    {
        $out = [];

        foreach (self::DEFAULT_UNITS as $unit) {
            $result = app('provisioning')->execute('service.status', ['service' => $unit]);
            $state = trim((string) ($result['output'] ?? ''));
            if ($state === '') {
                $state = 'unknown';
            }
            // systemctl is-active returns: active, inactive, failed, activating, deactivating
            $active = in_array($state, ['active', 'activating'], true);
            $out[] = ['name' => $unit, 'state' => $state, 'active' => $active];
        }

        return $out;
    }

    public function control(string $service, string $action): bool
    {
        $result = app('provisioning')->execute('service.control', [
            'service' => $service,
            'action' => $action,
        ]);
        return $result['status'] === 'success';
    }
}