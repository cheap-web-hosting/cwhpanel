<?php

declare(strict_types=1);

namespace Modules\Dns\Services;

use Modules\Domains\Services\DomainService;

/**
 * Server-wide nameservers.
 *
 * The nameserver pair belongs to the SERVER, not to a domain: every zone this
 * panel hosts is published with the same NS records, and only an administrator
 * can set them (Admin → DNS → Nameservers). Customers never see or edit them.
 *
 * Storage: `settings` (dns.nameserver1 / dns.nameserver2) with the legacy
 * DNS_NAMESERVER / DNS_NAMESERVER2 env values as the initial fallback, so an
 * existing install keeps working untouched.
 */
class DnsSettings
{
    public const KEY_NS1 = 'dns.nameserver1';
    public const KEY_NS2 = 'dns.nameserver2';
    public const KEY_MAIL_PREFIX = 'mail.hostname_prefix';
    public const KEY_DKIM_SELECTOR = 'dns.dkim_selector';

    /** @return array{0:?string,1:?string} [ns1, ns2] normalised hostnames */
    public static function get(): array
    {
        $ns1 = self::read(self::KEY_NS1);
        $ns2 = self::read(self::KEY_NS2);

        if ($ns1 === null) {
            $ns1 = DomainService::normalizeNameserver((string) env('DNS_NAMESERVER', ''));
        }
        if ($ns2 === null) {
            $ns2 = DomainService::normalizeNameserver((string) env('DNS_NAMESERVER2', ''));
        }

        if ($ns1 !== null && $ns2 === $ns1) {
            $ns2 = null;
        }

        return [$ns1, $ns2];
    }

    /**
     * Persist the server pair. Both values must already be valid hostnames
     * (callers validate to report which field is wrong); an empty pair is
     * allowed but means "this panel does not host DNS" (no zone is created).
     */
    public static function set(?string $ns1, ?string $ns2): void
    {
        self::write(self::KEY_NS1, $ns1 ?? '');
        self::write(self::KEY_NS2, $ns2 ?? '');
    }

    /**
     * Rewrite every hosted zone's NS rrset (and SOA mname) to the given pair.
     * Returns the number of zones updated; failures are logged and counted as
     * zero. Only zones the panel knows about (dns_zones) are touched.
     */
    public static function applyToAllZones(?string $ns1, ?string $ns2): int
    {
        if ($ns1 === null) {
            return 0;
        }

        $dns = app(DnsService::class);
        $zones = [];
        try {
            $zones = array_column(app('db')->select('SELECT `name` FROM `dns_zones` ORDER BY `name`'), 'name');
        } catch (\Throwable $e) {
            return 0;
        }

        $updated = 0;
        foreach ($zones as $zone) {
            try {
                $dns->setNameservers((string) $zone, $ns1, $ns2);
                $updated++;
            } catch (\Throwable $e) {
                app('logger')->warning('Nameserver update failed for zone ' . $zone . ': ' . $e->getMessage());
            }
        }

        return $updated;
    }

    /**
     * Per-domain mail hostname prefix ("mail."), used to build
     * mail.<domain>. Server-wide because every mailbox on this host shares the
     * same mail server. Falls back to MAIL_HOSTNAME, then "mail.".
     */
    public static function mailHostnamePrefix(): string
    {
        $value = self::readRaw(self::KEY_MAIL_PREFIX);
        if ($value === null || $value === '') {
            $value = (string) env('MAIL_HOSTNAME', 'mail.');
        }
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.$/', $value) !== 1) {
            return 'mail.';
        }
        return $value;
    }

    /** DKIM selector published as <selector>._domainkey.<domain>. */
    public static function dkimSelector(): string
    {
        $value = self::readRaw(self::KEY_DKIM_SELECTOR);
        if ($value === null || $value === '') {
            $value = (string) env('DKIM_SELECTOR', 'default');
        }
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $value) !== 1) {
            return 'default';
        }
        return $value;
    }

    /** @return array{0:string,1:string} validated [prefix, selector] */
    public static function validateMailSettings(string $prefix, string $selector): array
    {
        $prefix = strtolower(trim($prefix));
        $selector = strtolower(trim($selector));

        if ($prefix === '' || preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.$/', $prefix) !== 1 || strlen($prefix) > 60) {
            throw new \RuntimeException('Mail hostname prefix must be a DNS label prefix such as "mail." (letters, digits, dots, trailing dot).');
        }
        if ($selector === '' || preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $selector) !== 1) {
            throw new \RuntimeException('DKIM selector must be a single DNS label (letters, digits, hyphens).');
        }

        return [$prefix, $selector];
    }

    public static function setMailSettings(string $prefix, string $selector): void
    {
        self::write(self::KEY_MAIL_PREFIX, $prefix);
        self::write(self::KEY_DKIM_SELECTOR, $selector);
    }

    /**
     * Raw persisted settings (no env fallbacks), for tests and rollbacks.
     *
     * Tests that change server-wide settings must snapshot BEFORE touching
     * them: `get()` returns env fallbacks, and writing those back would turn a
     * read-only fallback into persisted state (it happened: a suite left
     * dns.nameserver1 = ns1.example.com on a production box).
     *
     * @return array<string,?string>
     */
    public static function snapshot(): array
    {
        return [
            self::KEY_NS1 => self::readRaw(self::KEY_NS1),
            self::KEY_NS2 => self::readRaw(self::KEY_NS2),
            self::KEY_MAIL_PREFIX => self::readRaw(self::KEY_MAIL_PREFIX),
            self::KEY_DKIM_SELECTOR => self::readRaw(self::KEY_DKIM_SELECTOR),
        ];
    }

    /**
     * Restore a snapshot EXACTLY: keys that did not exist are deleted again,
     * existing ones get their original value back.
     *
     * @param array<string,?string> $snapshot
     */
    public static function restore(array $snapshot): void
    {
        $db = app('db');
        foreach ($snapshot as $key => $value) {
            try {
                if ($value === null) {
                    $db->delete('settings', '`key` = ?', [$key]);
                    continue;
                }
                if ($db->selectOne('SELECT `key` FROM `settings` WHERE `key` = ?', [$key]) !== null) {
                    $db->update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`key` = ?', [$key]);
                } else {
                    $db->insert('settings', ['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
                }
            } catch (\Throwable $e) {
                try { app('logger')->warning("DnsSettings::write({$key}) failed: " . $e->getMessage()); } catch (\Throwable) {}
            }
        }
    }

    private static function read(string $key): ?string
    {
        try {
            $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', [$key]);
        } catch (\Throwable $e) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        return DomainService::normalizeNameserver((string) ($row['value'] ?? ''));
    }

    /** Raw setting value (no hostname normalisation), null when unset. */
    private static function readRaw(string $key): ?string
    {
        try {
            $row = app('db')->selectOne('SELECT `value` FROM `settings` WHERE `key` = ?', [$key]);
        } catch (\Throwable $e) {
            return null;
        }
        return $row === null ? null : (string) ($row['value'] ?? '');
    }

    private static function write(string $key, string $value): void
    {
        $db = app('db');
        try {
            if ($db->selectOne('SELECT `key` FROM `settings` WHERE `key` = ?', [$key]) !== null) {
                $db->update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`key` = ?', [$key]);
            } else {
                $db->insert('settings', ['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
            }
        } catch (\Throwable $e) {
            app('logger')->error('Failed to persist ' . $key . ': ' . $e->getMessage());
        }
    }
}
