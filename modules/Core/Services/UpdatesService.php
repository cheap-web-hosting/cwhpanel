<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * Host update management (admin → Server → Updates).
 *
 *  - Automatic updates run from a managed `/etc/cron.d/cwhpanel-updates`
 *    drop-in the privileged agent writes (no extra RPM dependency; cron mails
 *    the run output to root, which the root-forward control below can route
 *    off-box). Mode is Off / Security only / All.
 *  - Pending-update status is produced by an async job
 *    (`system.updates.check`) calling `dnf check-update` + `updateinfo list
 *    security`, and cached in the `settings` table.
 *  - Root email forwarding points the `root:` alias in /etc/aliases at an
 *    external address (or clears it) via atomic read-modify-write.
 */
class UpdatesService
{
    private const MODE_KEY = 'system.updates_mode';
    private const STATUS_KEY = 'system.updates_status';

    public const MODES = ['off', 'security', 'all'];

    public function mode(): string
    {
        $v = (string) ($this->setting(self::MODE_KEY) ?? 'off');
        return in_array($v, self::MODES, true) ? $v : 'off';
    }

    /** Persist the mode and (re)write the managed cron drop-in. */
    public function setMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \RuntimeException('Invalid update mode.');
        }
        if (app(\Modules\Core\Services\PanelManagerService::class)->policyBlocked()) {
            throw new \RuntimeException('Updates are blocked by the Panel Manager. Re-enable updates there first.');
        }
        $this->put(self::MODE_KEY, $mode);
        app('provisioning')->execute('updates.configure', ['mode' => $mode]);
    }

    /** Cached last-check snapshot (empty until the first check runs). */
    public function status(): array
    {
        $raw = (string) ($this->setting(self::STATUS_KEY) ?? '');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Query dnf (blocking; normally run from the async job) and cache the
     * snapshot. Returns it.
     *
     * @return array{checked_at:string,total:int,security:int,packages:array<int,string>}
     */
    public function checkNow(): array
    {
        $pending = (string) (app('provisioning')->execute('updates.pending')['output'] ?? '');
        $security = (string) (app('provisioning')->execute('updates.security')['output'] ?? '');

        $packages = [];
        $total = 0;
        foreach (preg_split('/\r\n|\r|\n/', $pending) ?: [] as $line) {
            if (preg_match('/^(\S+?)\.(x86_64|noarch|i686|aarch64)\s/', $line, $m) === 1) {
                $total++;
                if (count($packages) < 25) {
                    $packages[] = $m[1];
                }
            }
        }
        $sec = 0;
        foreach (preg_split('/\r\n|\r|\n/', $security) ?: [] as $line) {
            if (trim($line) !== '') {
                $sec++;
            }
        }

        $snapshot = [
            'checked_at' => date('Y-m-d H:i:s'),
            'total' => $total,
            'security' => $sec,
            'packages' => $packages,
        ];
        $this->put(self::STATUS_KEY, json_encode($snapshot));
        return $snapshot;
    }

    /**
     * Run dnf now (blocking; normally from the async `system.updates.apply`
     * job) for the given scope and refresh the pending snapshot. Returns a
     * short summary for the job result.
     */
    public function applyNow(string $mode = 'security'): string
    {
        if (!in_array($mode, ['security', 'all'], true)) {
            throw new \RuntimeException('Invalid update scope.');
        }
        if (app(\Modules\Core\Services\PanelManagerService::class)->policyBlocked()) {
            throw new \RuntimeException('Updates are blocked by the Panel Manager. Re-enable updates there first.');
        }
        $res = app('provisioning')->execute('updates.apply', ['packages' => '', 'mode' => $mode]);
        $summary = $this->checkNow();
        $note = trim(mb_substr((string) ($res['output'] ?? ''), 0, 400));
        return ($mode === 'all' ? 'All' : 'Security') . ' update run finished; '
            . $summary['total'] . ' pending (' . $summary['security'] . " security).\n" . $note;
    }

    /**
     * Most recent update job runs (check + apply), newest first. Used by the
     * Updates page so queued/run work is visible instead of silent.
     *
     * @return array<int,array{id:int,type:string,status:string,created_at:string,result:string}>
     */
    public function recentRuns(int $limit = 6): array
    {
        try {
            $rows = app('db')->select(
                "SELECT `id`,`type`,`status`,`created_at`,`result`
                 FROM `jobs`
                 WHERE `type` IN ('system.updates.check','system.updates.apply')
                 ORDER BY `id` DESC LIMIT " . max(1, min(20, $limit))
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'status' => (string) $r['status'],
            'created_at' => (string) $r['created_at'],
            'result' => (string) $r['result'],
        ], $rows);
    }

    /**
     * Whether a reboot is required before installed updates fully take effect
     * (running kernel < newest installed kernel, plus needs-restarting detail).
     * Best-effort: returns null when the agent is unreachable (the pages hide
     * the panel rather than fail).
     *
     * @return array{required:bool,running_kernel:string,newest_installed_kernel:string,detail:string}|null
     */
    public function rebootRequired(): ?array
    {
        try {
            $out = (string) (app('provisioning')->execute('system.reboot.required')['output'] ?? '');
        } catch (\Throwable $e) {
            app('logger')->debug('reboot check unavailable: ' . $e->getMessage());
            return null;
        }
        $data = ['required' => false, 'running_kernel' => '', 'newest_installed_kernel' => '', 'detail' => ''];
        foreach (preg_split('/\r\n|\r|\n/', $out) ?: [] as $line) {
            if (preg_match('/^(\w+)=(.*)$/', trim($line), $m) === 1) {
                if ($m[1] === 'required') {
                    $data['required'] = $m[2] === '1';
                } elseif (isset($data[$m[1]]) || $m[1] === 'detail') {
                    $data[$m[1]] = $m[2];
                }
            }
        }
        return $data;
    }

    /** The current `root:` forward target, or null when forwarding is off. */
    public function rootForward(): ?string
    {
        $cur = (string) (app('provisioning')->execute('mail.aliases.read')['output'] ?? '');
        foreach (preg_split('/\r\n|\r|\n/', $cur) ?: [] as $line) {
            $t = ltrim($line);
            if ($t !== '' && $t[0] !== '#' && preg_match('/^root\s*:\s*(\S+)/i', $t, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    /** Point `root:` at $email (null/'' clears forwarding), keeping all other lines. */
    public function setRootForward(?string $email): void
    {
        $email = $email === null ? null : trim($email);
        if ($email === '') {
            $email = null;
        }
        if ($email !== null) {
            if (preg_match('/[\r\n\0]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Enter a valid email address.');
            }
        }

        $cur = (string) (app('provisioning')->execute('mail.aliases.read')['output'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $cur) ?: [];
        $out = [];
        $done = false;
        $lastIdx = count($lines) - 1;
        foreach ($lines as $i => $line) {
            if ($i === $lastIdx && $line === '') {
                continue; // drop the artifact of the file's trailing newline
            }
            $t = ltrim($line);
            $isRoot = $t !== '' && $t[0] !== '#' && preg_match('/^root\s*:/i', $t) === 1;
            if ($isRoot) {
                if (!$done) {
                    if ($email !== null) {
                        $out[] = "root: {$email}";
                    }
                    $done = true;
                }
                continue; // collapse duplicate root lines
            }
            $out[] = $line;
        }
        if (!$done && $email !== null) {
            $out[] = "root: {$email}";
        }

        app('provisioning')->execute('mail.aliases.write', ['contents' => implode("\n", $out) . "\n"]);
    }

    private function setting(string $key): ?string
    {
        $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', [$key]);
        return $row === null ? null : (string) $row['value'];
    }

    private function put(string $key, string $value): void
    {
        $db = app('db');
        if ($db->selectOne('SELECT `key` FROM `settings` WHERE `key` = ?', [$key])) {
            $db->update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`key` = ?', [$key]);
        } else {
            $db->insert('settings', ['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }
}