<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * PanelManager reporter — phones home to the central PanelManager dashboard
 * (https://serverstats.cheap-web-hosting.org by default).
 *
 * Features:
 *  - Registers this installation on first run (hostname → installation id +
 *    secret, stored in the settings table).
 *  - Sends an hourly heartbeat: hostname, IP, panel version, hosted account
 *    count, license type/key.
 *  - Applies the server policy: a banned hostname is reported (status) and
 *    `block_updates` freezes all update operations on this panel (see
 *    UpdatesService / VulnerabilityService gates).
 *
 * Everything is opt-in: without `PANEL_MANAGER_URL` in `.env` the service is
 * inert. Network/reporting failures are logged and surfaced in the status,
 * never thrown (the cron must never break because the dashboard is down).
 */
class PanelManagerService
{
    private const SETTING_ID = 'panel_manager_installation_id';
    private const SETTING_SECRET = 'panel_manager_secret';
    private const SETTING_STATUS = 'panel_manager_status';
    private const SETTING_POLICY_BLOCK = 'panel_manager_policy_block_updates';
    private const SETTING_LICENSE_STATUS = 'panel_manager_license_status';
    private const SETTING_LICENSE_REASON = 'panel_manager_license_reason';
    private const SETTING_LAST_SYNC = 'panel_manager_last_sync';

    public function configured(): bool
    {
        return trim((string) app('env')->get('PANEL_MANAGER_URL')) !== '';
    }

    public function url(): string
    {
        return rtrim((string) app('env')->get('PANEL_MANAGER_URL'), '/');
    }

    /** Current reporting payload (mirrors the PanelManager API contract). */
    public function payload(): array
    {
        $hostname = '127.0.0.1';
        try {
            $hostname = app(\Modules\Core\Services\HostnameService::class)->current();
        } catch (\Throwable) {
            $hostname = strtolower((string) gethostname());
        }

        $ip = (string) app('env')->get('PANEL_PUBLIC_IP');
        if ($ip === '') {
            $ip = (string) @gethostbyname($hostname);
        }

        $accounts = 0;
        try {
            $accounts = (int) app('db')->selectOne(
                "SELECT COUNT(*) AS c FROM `accounts` WHERE `status` <> 'terminated'"
            )['c'] ?? 0;
        } catch (\Throwable) {
            // accounts table absent pre-migration — report 0
        }

        $license = (string) app('env')->get('PANEL_MANAGER_LICENSE_TYPE');
        if (!in_array($license, ['free', 'paid'], true)) {
            $license = 'free';
        }

        return [
            'hostname' => $hostname,
            'ip' => $ip,
            'version' => app(\Modules\Core\Services\PanelVersionService::class)->current(),
            'account_count' => $accounts,
            'license_type' => $license,
            'license_key' => (string) app('env')->get('PANEL_MANAGER_LICENSE_KEY'),
        ];
    }

    /** POST JSON to the PanelManager API. Returns [status, decoded body]. */
    private function http(string $path, array $body, ?string $token = null): array
    {
        // A recognised client User-Agent (plus an explicit JSON Accept) is required:
        // the dashboard sits behind Cloudflare/LiteSpeed bot verification, which
        // challenges UA-less/scripted sends — PHP's file_get_contents() emits no UA
        // and gets served a 200 "Bot Verification" HTML page instead of the JSON API.
        $headers = "Content-Type: application/json\r\n"
            . "Accept: application/json\r\n"
            . "User-Agent: curl/8.4.0\r\n";
        if ($token !== null) {
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => json_encode($body),
            'ignore_errors' => true,
            'timeout' => 12,
        ]]);
        $raw = @file_get_contents($this->url() . $path, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        $decoded = json_decode((string) $raw, true);
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
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

    private function applyPolicy(array $policy): void
    {
        if (isset($policy['block_updates'])) {
            $blocked = (bool) $policy['block_updates'];
            $this->put(self::SETTING_POLICY_BLOCK, $blocked ? '1' : '0');
        }
        if (isset($policy['license_status'])) {
            $this->put(self::SETTING_LICENSE_STATUS, (string) $policy['license_status']);
        }
        if (isset($policy['license_reason'])) {
            $this->put(self::SETTING_LICENSE_REASON, substr((string) $policy['license_reason'], 0, 255));
        }
        if (isset($policy['block_reason'])) {
            $this->put('panel_manager_policy_block_reason', substr((string) $policy['block_reason'], 0, 255));
        }
    }

    private function setStatus(array $status): void
    {
        $status['at'] = date('Y-m-d H:i:s');
        $this->put(self::SETTING_STATUS, json_encode($status));
        $this->put(self::SETTING_LAST_SYNC, $status['at']);
    }

    /**
     * True when the Panel Manager policy blocks update operations on this box
     * (and reporting is configured). The source of truth is the last applied
     * policy — the panel refuses updates until the dashboard unblocks us.
     */
    public function policyBlocked(): bool
    {
        return $this->configured() && $this->setting(self::SETTING_POLICY_BLOCK) === '1';
    }

    /** Why updates are blocked (license_invalid / license_expired / operator), or ''. */
    public function blockReason(): string
    {
        return (string) $this->setting('panel_manager_policy_block_reason');
    }

    /** Latest license verdict from the dashboard: free|valid|invalid|expired|revoked|unknown. */
    public function licenseStatus(): string
    {
        return (string) ($this->setting(self::SETTING_LICENSE_STATUS) ?: 'unknown');
    }

    /** Human-readable reason for the latest license verdict. */
    public function licenseReason(): string
    {
        return (string) $this->setting(self::SETTING_LICENSE_REASON);
    }

    /** Last sync/status summary (for UI/debug), or null when never synced. */
    public function status(): ?array
    {
        $raw = $this->setting(self::SETTING_STATUS);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Whether the dashboard told us this hostname is banned. */
    public function banned(): bool
    {
        $s = $this->status();
        return ($s['banned'] ?? false) === true;
    }

    /**
     * Register + report (called from the hourly cron / CLI). Never throws for
     * network failures. Returns a summary array for logging.
     */
    public function sync(): array
    {
        if (!$this->configured()) {
            return ['skipped' => true, 'reason' => 'not configured'];
        }

        $payload = $this->payload();
        $id = $this->setting(self::SETTING_ID);
        $secret = $this->setting(self::SETTING_SECRET);

        // Registration (first run, re-register after a secret mismatch, or
        // when a previous run stranded empty credential rows).
        if ($id === null || $id === '' || $secret === null || $secret === '') {
            return $this->register($payload);
        }

        // Heartbeat.
        $res = $this->http('/api/v1/report', $payload, $id . '.' . $secret);
        if ($res['status'] === 200) {
            $this->applyPolicy($res['body']['policy'] ?? []);
            $this->setStatus(['http_status' => 200, 'event' => 'report']);
            return ['reported' => true];
        }
        if ($res['status'] === 401) {
            // Secret rotated on the server — re-register to mint a new pair
            // (one attempt; no recursion).
            $this->put(self::SETTING_ID, '');
            $this->put(self::SETTING_SECRET, '');
            return $this->register($payload);
        }
        if ($res['status'] === 403 && ($res['body']['error'] ?? '') === 'hostname_banned') {
            $this->setStatus(['http_status' => 403, 'banned' => true, 'error' => 'hostname_banned']);
            return ['banned' => true];
        }
        $this->setStatus(['http_status' => $res['status'], 'error' => 'report failed']);
        app('logger')->warning('PanelManager report failed (http ' . $res['status'] . ')');
        return ['reported' => false, 'http_status' => $res['status']];
    }

    /** Register (or re-register) this installation. Called at most once per sync. */
    private function register(array $payload): array
    {
        $res = $this->http('/api/v1/register', $payload);
        if ($res['status'] === 200 && isset($res['body']['installation_id'], $res['body']['secret'])) {
            $this->put(self::SETTING_ID, (string) $res['body']['installation_id']);
            $this->put(self::SETTING_SECRET, (string) $res['body']['secret']);
            $this->applyPolicy($res['body']['policy'] ?? []);
            $this->setStatus(['http_status' => 200, 'event' => 'registered']);
            return ['registered' => true];
        }
        if ($res['status'] === 403 && ($res['body']['error'] ?? '') === 'hostname_banned') {
            $this->setStatus(['http_status' => 403, 'banned' => true, 'error' => 'hostname_banned']);
            return ['banned' => true];
        }
        $this->setStatus(['http_status' => $res['status'], 'error' => 'register failed']);
        app('logger')->warning('PanelManager register failed (http ' . $res['status'] . ')');
        return ['registered' => false, 'http_status' => $res['status']];
    }
}
