<?php

declare(strict_types=1);

namespace Modules\Network\Services;

/**
 * Per-account application network namespaces (L-14).
 *
 * When enabled (setting network.app_netns_enabled, default ON), each account's
 * applications run inside a dedicated network namespace reachable only via a
 * host veth pair. Account A can no longer reach account B's loopback app
 * services; outbound internet works through host NAT, and the host (nginx
 * reverse proxy) reaches the app at its namespace address.
 */
class AppNetnsService
{
    public const ENABLED_KEY = 'network.app_netns_enabled';

    public function enabled(): bool
    {
        // Default on: missing setting (fresh install) means isolated. An admin
        // can still turn it off explicitly via Server -> Network.
        return trim((string) ($this->setting(self::ENABLED_KEY) ?? '1')) === '1';
    }

    public function setEnabled(bool $on): void
    {
        $this->putSetting(self::ENABLED_KEY, $on ? '1' : '0');
    }

    /** Namespace address the host proxies to for an account id. */
    public static function addressFor(int $accountId): string
    {
        $id = max(1, min(16383, $accountId));
        $hi = ($id >> 6) & 255;
        $base = ($id & 63) << 2;
        return '10.213.' . $hi . '.' . ($base + 2);
    }

    /** Create/converge the account namespace (best-effort, never fatal). */
    public function ensure(array $account): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $username = (string) ($account['username'] ?? '');
        if ($username === '') {
            return false;
        }
        // Callers may pass a partial account array (tests, freshly built rows):
        // an undefined 'id' used to raise a PHP warning on every app start.
        $accountId = (int) ($account['id'] ?? 0);
        if ($accountId <= 0) {
            return false;
        }
        try {
            $r = app('provisioning')->execute('netns.account.ensure', [
                'account' => $username,
                'id' => (string) $accountId,
            ]);
            return ($r['status'] ?? '') === 'success';
        } catch (\Throwable $e) {
            app('logger')->warning("app netns ensure failed for {$username}: " . $e->getMessage());
            return false;
        }
    }

    public function destroy(array $account): void
    {
        $username = (string) ($account['username'] ?? '');
        if ($username === '') {
            return;
        }
        try {
            app('provisioning')->execute('netns.account.destroy', [
                'account' => $username,
                'id' => (string) (int) ($account['id'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            app('logger')->warning("app netns destroy failed for {$username}: " . $e->getMessage());
        }
    }

    /** True when the account currently has a live namespace. */
    public function active(array $account): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $username = (string) ($account['username'] ?? '');
        if ($username === '') {
            return false;
        }
        try {
            $r = app('provisioning')->execute('netns.account.status', [
                'account' => $username,
            ]);
            return trim((string) ($r['output'] ?? '')) === 'up';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Host-side proxy target for an app (namespace address or loopback). */
    public function proxyHost(int $accountId): string
    {
        return $this->enabled() ? self::addressFor($accountId) : '127.0.0.1';
    }

    private function setting(string $key): ?string
    {
        $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', [$key]);
        return $row ? (string) $row['value'] : null;
    }

    private function putSetting(string $key, string $value): void
    {
        $db = app('db');
        if ($db->selectOne('SELECT `key` FROM `settings` WHERE `key` = ?', [$key])) {
            $db->update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`key` = ?', [$key]);
        } else {
            $db->insert('settings', ['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }
}
