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

    /**
     * Create/converge the account namespace and verify it is actually up.
     *
     * Isolation is a security property (audit #20): when the feature is
     * enabled, a failure MUST abort the app operation. Falling back to the
     * shared host network would let the app reach every other account's
     * loopback services. The admin turns the feature off explicitly to choose
     * shared networking.
     *
     * @throws \RuntimeException when the namespace cannot be established
     */
    public function ensure(array $account): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $username = (string) ($account['username'] ?? '');
        $accountId = (int) ($account['id'] ?? 0);
        if ($username === '' || $accountId <= 0) {
            throw new \RuntimeException(
                'Network namespace isolation is enabled, but the account has no persisted id; refusing to start without isolation.'
            );
        }
        // Let agent/provisioning errors propagate: the caller must not start
        // the app on the shared host network.
        $r = app('provisioning')->execute('netns.account.ensure', [
            'account' => $username,
            'id' => (string) $accountId,
        ]);
        if (($r['status'] ?? '') !== 'success') {
            throw new \RuntimeException(
                'Network namespace setup failed for ' . $username
                . ' (' . (string) ($r['status'] ?? 'error') . ')'
                . (trim((string) ($r['output'] ?? '')) !== '' ? ': ' . trim((string) $r['output']) : '')
            );
        }
        if (!$this->active($account)) {
            throw new \RuntimeException('Network namespace for ' . $username . ' did not come up.');
        }
        return true;
    }

    /**
     * The bind host an app must use: the account's namespace address when
     * isolation is enabled (verified), or loopback when the administrator
     * explicitly disabled the feature. Throws instead of falling back.
     */
    public function ensureHost(array $account): string
    {
        if (!$this->enabled()) {
            return '127.0.0.1';
        }
        $this->ensure($account);
        return self::addressFor((int) $account['id']);
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
