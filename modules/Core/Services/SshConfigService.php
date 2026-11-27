<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * Server-wide sshd settings (admin → Server → SSH).
 *
 * The sshd_config is read/written through the agent with an `sshd -t` gate
 * and reload, so an invalid edit rolls back instead of locking access out.
 * Firewall ports are managed via the existing firewall ops.
 */
class SshConfigService
{
    public const DEFAULT_PORT = 22;

    private ?string $lastError = null;

    /** Last read/probe error, or null when the last call was clean. */
    public function error(): ?string
    {
        return $this->lastError;
    }

    /** Active sshd port (first uncommented `Port N` line), default 22. */
    public function port(): int
    {
        $this->lastError = null;
        try {
            $raw = (string) (app('provisioning')->execute('ssh.config.read', [])['output'] ?? '');
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return self::DEFAULT_PORT;
        }
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#') {
                continue;
            }
            if (preg_match('/^Port\s+(\d+)$/i', $t, $m)) {
                $port = (int) $m[1];
                if ($port >= 1 && $port <= 65535) {
                    return $port;
                }
            }
        }
        return self::DEFAULT_PORT;
    }

    /**
     * Change the sshd port. Returns the previous port.
     * $keepOld: also leave the previous port open in firewalld (see note).
     */
    public function setPort(int $port, bool $keepOld = true): int
    {
        $old = $this->port();
        $raw = (string) (app('provisioning')->execute('ssh.config.read', [])['output'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        // The single `Port` line is emitted at the TOP of the file: appending
        // after a `Match` block makes `sshd -t` reject it ("Port is not
        // allowed within a Match block"). Remove any existing active Port lines.
        $out = ['Port ' . $port];
        foreach ($lines as $line) {
            $t = ltrim($line);
            if ($t !== '' && $t[0] !== '#' && preg_match('/^Port\s+\d+$/i', $t)) {
                continue;
            }
            $out[] = $line;
        }
        app('provisioning')->execute('ssh.config.write', ['contents' => implode("\n", $out) . "\n"]);

        // Firewall: always open the new port; optionally keep the old one too.
        try {
            app('provisioning')->execute('firewall.port.open', ['port' => (string) $port, 'protocol' => 'tcp']);
        } catch (\Throwable $e) {
            app('logger')->warning('Firewall open for ssh port failed: ' . $e->getMessage());
        }
        if (!$keepOld && $old !== $port) {
            try {
                app('provisioning')->execute('firewall.port.close', ['port' => (string) $old, 'protocol' => 'tcp']);
            } catch (\Throwable $e) {
                app('logger')->warning('Firewall close for old ssh port failed: ' . $e->getMessage());
            }
        }
        return $old;
    }
}
