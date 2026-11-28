<?php

declare(strict_types=1);

namespace Modules\Security\Services;

/**
 * SshService — per-account SSH access: authorized_keys management plus a
 * three-state login shell:
 *
 *   off    — /sbin/nologin, no SSH at all;
 *   jailed — /bin/bash inside a chroot jail (/jail/<user>, sshjailed group);
 *   full   — /bin/bash on the host (no confinement).
 *
 * Keys are stored only in ~/.ssh/authorized_keys (no DB); the file is fully
 * rewritten on every change through the agent (ssh.keys.write, validated +
 * sievec-style strict key checking, 600 perms, user-owned dir). Shell reads
 * come straight from /etc/passwd; mode writes go through ssh.shell.set plus
 * ssh.jail.create/destroy. Mode is derived from live system state (shell +
 * jail status), never stored.
 */
class SshService
{
    public const MODES = ['off', 'jailed', 'full'];

    public const MODE_LABELS = [
        'off' => 'Disabled',
        'jailed' => 'Jailed (chroot)',
        'full' => 'Full access',
    ];
    /**
     * @return array<int, array{index:int, type:string, comment:string, fingerprint:string}>
     */
    public function keys(string $username): array
    {
        $result = app('provisioning')->execute('ssh.keys.list', ['account' => $username]);
        return self::parseKeys((string) ($result['output'] ?? ''));
    }

    /**
     * Parse authorized_keys content into structured rows (pure, testable).
     *
     * @return array<int, array{index:int, type:string, comment:string, fingerprint:string}>
     */
    public static function parseKeys(string $contents): array
    {
        $rows = [];
        $index = 0;
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 3);
            if (count($parts) < 2) {
                continue;
            }
            $rows[] = [
                'index' => $index++,
                'type' => $parts[0],
                'comment' => $parts[2] ?? '',
                'fingerprint' => 'SHA256:' . rtrim(strtr(base64_encode(hash('sha256', $parts[1], true)), '+/', '-_'), '='),
            ];
        }
        return $rows;
    }

    /**
     * Raw key lines (comments/blank lines/non-key noise dropped), preserving
     * file order. Only lines starting with a known key type are kept, so
     * stray stderr text accidentally captured in command output can never be
     * written back into authorized_keys.
     *
     * @return array<int,string>
     */
    public static function keyLines(string $contents): array
    {
        $lines = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com) /', $line)) {
                continue;
            }
            $lines[] = $line;
        }
        return array_values($lines);
    }

    public function addKey(string $username, string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'error' => 'Empty key.'];
        }
        $current = $this->rawKeys($username);
        $newBody = self::keyBody($key);
        foreach ($current as $line) {
            if (self::keyBody($line) === $newBody && $newBody !== null) {
                return ['ok' => false, 'error' => 'Key already authorized.'];
            }
        }
        $current[] = $key;
        return $this->writeKeys($username, $current);
    }

    public function removeKey(string $username, int $index): array
    {
        $current = $this->rawKeys($username);
        if (!isset($current[$index])) {
            return ['ok' => false, 'error' => 'Key not found.'];
        }
        unset($current[$index]);
        return $this->writeKeys($username, array_values($current));
    }

    public function shell(string $username): string
    {
        $pw = posix_getpwnam($username);
        if ($pw === false) {
            return 'unknown';
        }
        return basename((string) ($pw['shell'] ?? ''));
    }

    public function sshEnabled(string $username): bool
    {
        return $this->shell($username) !== 'nologin';
    }

    /**
     * Effective access mode, derived from live state: nologin shell means
     * off; otherwise a mounted jail + group membership means jailed.
     */
    public function mode(string $username): string
    {
        return $this->modeOrNull($username) ?? 'full';
    }

    /**
     * Effective access mode, or NULL when the agent is unreachable (the jail
     * half of the state cannot be verified, so neither "jailed" nor "full"
     * may be asserted). Disabled is derived from the local shell only.
     */
    public function modeOrNull(string $username): ?string
    {
        if ($this->shell($username) === 'nologin') {
            return 'off';
        }
        $st = $this->jailState($username);
        if ($st === null) {
            return null;
        }
        return (($st['mounted'] ?? false) && ($st['group'] ?? false)) ? 'jailed' : 'full';
    }

    public function jailed(string $username): bool
    {
        $st = $this->jailStatus($username);
        return ($st['mounted'] ?? false) && ($st['group'] ?? false);
    }

    /** @return array{dir:bool, mounted:bool, group:bool} */
    public function jailStatus(string $username): array
    {
        return $this->jailState($username) ?? ['dir' => false, 'mounted' => false, 'group' => false];
    }

    /**
     * Live jail state, or NULL when the provisioning agent could not be
     * reached. Callers that render "jailed / full / off" MUST distinguish
     * NULL from a real answer — an unreachable agent is not "full access".
     *
     * @return array{dir:bool,mounted:bool,group:bool}|null
     */
    public function jailState(string $username): ?array
    {
        $out = ['dir' => false, 'mounted' => false, 'group' => false];
        try {
            $result = app('provisioning')->execute('ssh.jail.status', ['account' => $username]);
        } catch (\Throwable $e) {
            return null;
        }
        foreach (explode("\n", (string) ($result['output'] ?? '')) as $line) {
            $parts = explode('=', trim($line), 2);
            if (count($parts) === 2 && array_key_exists($parts[0], $out)) {
                $out[$parts[0]] = ($parts[1] === 'yes');
            }
        }
        return $out;
    }

    /**
     * Switch access mode. Jail teardown is best-effort (leaving a stale jail
     * mounted is worse than a loud error only when the shell change itself
     * fails). A failed jail provision leaves the bash shell in place and says
     * so — full access with an error, never a silent lockout.
     */
    public function setMode(string $username, string $mode): array
    {
        if (!in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'error' => 'Invalid SSH mode.'];
        }
        if ($mode === 'jailed') {
            // FAIL CLOSED: keep the shell at nologin until the jail has been
            // created AND verified. The old order (bash first, jail second)
            // left an unrestricted host shell behind whenever chroot setup
            // failed — a restricted mode that silently granted full access.
            $this->setShellRaw($username, 'nologin');

            try {
                $result = app('provisioning')->execute('ssh.jail.create', ['account' => $username]);
            } catch (\Throwable $e) {
                $this->setShellRaw($username, 'nologin');
                return ['ok' => false, 'error' => 'Jail provisioning failed; SSH left disabled: ' . $e->getMessage()];
            }
            if (($result['status'] ?? '') !== 'success') {
                $this->setShellRaw($username, 'nologin');
                return ['ok' => false, 'error' => 'Jail provisioning failed; SSH left disabled.'];
            }

            // Verify the chroot really exists and is mounted before enabling a
            // login shell (a half-built jail is not a jail).
            $state = $this->jailState($username);
            if ($state === null || empty($state['mounted']) || empty($state['group'])) {
                $this->setShellRaw($username, 'nologin');
                return ['ok' => false, 'error' => 'Jail verification failed; SSH left disabled.'];
            }

            $shell = $this->setShellRaw($username, 'bash');
            if (!$shell['ok']) {
                $this->setShellRaw($username, 'nologin');
                return ['ok' => false, 'error' => 'Jail is ready but the login shell could not be enabled.'];
            }
            // The jail's /etc/passwd was snapshotted while the account was
            // still nologin; sync it to the host entry now that bash is on.
            // (A full jail_create re-run is unsafe: chmod on the read-only /usr
            // bind fails on an already-provisioned jail.)
            try {
                app('provisioning')->execute('ssh.jail.passwd.sync', ['account' => $username]);
            } catch (\Throwable $e) {
                app('logger')->warning('Jail passwd sync failed for ' . $username . ': ' . $e->getMessage());
            }
            return ['ok' => true];
        }
        try {
            app('provisioning')->execute('ssh.jail.destroy', ['account' => $username]);
        } catch (\Throwable $e) {
            app('logger')->warning('Jail teardown failed for ' . $username . ': ' . $e->getMessage());
        }
        return $this->setShellRaw($username, $mode === 'full' ? 'bash' : 'nologin');
    }

    public function setShell(string $username, bool $enabled): array
    {
        return $this->setMode($username, $enabled ? 'full' : 'off');
    }

    private function setShellRaw(string $username, string $shell): array
    {
        if ($shell !== 'bash' && $shell !== 'nologin') {
            return ['ok' => false, 'error' => 'Invalid shell.'];
        }
        try {
            $result = app('provisioning')->execute('ssh.shell.set', [
                'account' => $username,
                'shell' => $shell,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => $result['status'] === 'success'];
    }

    /** @return array<int,string> */
    private function rawKeys(string $username): array
    {
        try {
            $result = app('provisioning')->execute('ssh.keys.list', ['account' => $username]);
        } catch (\Throwable $e) {
            return [];
        }
        return self::keyLines((string) ($result['output'] ?? ''));
    }

    private static function keyBody(string $line): ?string
    {
        $parts = preg_split('/\s+/', trim($line), 3);
        return isset($parts[1]) ? $parts[1] : null;
    }

    private function writeKeys(string $username, array $lines): array
    {
        try {
            $result = app('provisioning')->execute('ssh.keys.write', [
                'account' => $username,
                'contents' => implode("\n", $lines) . "\n",
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($result['status'] !== 'success') {
            return ['ok' => false, 'error' => 'Key update failed.'];
        }
        return ['ok' => true];
    }
}
