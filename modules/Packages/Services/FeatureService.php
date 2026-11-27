<?php

declare(strict_types=1);

namespace Modules\Packages\Services;

use App\Foundation\Auth\ForbiddenException;
use App\Foundation\Auth\User;

/**
 * FeatureService — package-tied feature manager.
 *
 * Each hosting package enables a subset of customer-facing features (one per
 * module: websites, email, databases, ...). Enabled rows live in
 * `package_features` (package_id, feature); absence means disabled.
 *
 * Enforcement has two layers, both keyed off the module id:
 *  - routes: `feature.<module>` middleware on every customer group
 *    (admins bypass everything — this is a customer/package control);
 *  - nav: filterNav() strips disabled modules from the customer sidebar.
 *
 * A third, service layer closes the force-it paths the first two cannot
 * see: AppService (create/start/deploy/package installs), InstallerService
 * (create/update/execute) and cron creation all call requireForAccount()
 * with the target account row, so queued worker jobs and direct service
 * calls are gated exactly like the panel routes. Package saves sweep
 * accounts that lose an execution-bearing feature (PackageController):
 * running app units stop, cron rows pause + resync, SSH shells lock.
 *
 * Fail-open corners (documented, not accidental): unknown feature keys,
 * missing account rows, and packages with zero feature rows (legacy data)
 * all resolve to enabled — controllers still scope by account, and the nav
 * is visibility-only. The route middleware is the real gate.
 */
class FeatureService
{
    private \App\Foundation\Database\Connection $db;

    public function __construct(\App\Foundation\Database\Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Catalog: module id => [label, description]. Order is the package-form
     * checkbox order. Keys must stay lowercase module ids — nav items and
     * middleware names derive from them.
     */
    public const FEATURES = [
        'websites' => ['Websites', 'Nginx sites, PHP settings, logs, bot shield, directory privacy'],
        'domains' => ['Domains', 'Main/addon/parked/subdomain management'],
        'dns' => ['DNS', 'PowerDNS zones, records and templates'],
        'email' => ['Email', 'Mailboxes, forwarders, spam and sieve filters'],
        'webmail' => ['Webmail', 'Browser inbox (needs Email for mailbox management)'],
        'databases' => ['Databases', 'MariaDB databases, users and phpMyAdmin'],
        'files' => ['File Manager', 'Browser file manager, upload and archives'],
        'ftp' => ['FTP', 'FTP accounts bound to the system user'],
        'ssl' => ['SSL', 'Self-signed and Let\'s Encrypt certificates'],
        'cron' => ['Cron Jobs', 'Scheduled tasks for the account user'],
        'backups' => ['Backups', 'File/database backups, restore and download'],
        'applications' => ['Node.js & Python apps', 'Runtime apps with git deploys (separate Node.js / Python pages)'],
        'appinstaller' => ['App Installer', 'One-click installs (WordPress, …)'],
        'monitoring' => ['Monitoring', 'Resource usage and traffic analytics'],
        'security' => ['Security', 'Firewall view, WAF, antivirus and SSH keys'],
    ];

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::FEATURES);
    }

    public static function label(string $feature): string
    {
        return self::FEATURES[$feature][0] ?? $feature;
    }

    /**
     * Enabled map for a package. A package with zero feature rows (legacy /
     * pre-backfill data) resolves to all-enabled so behavior never regresses.
     *
     * @return array<string,bool>
     */
    public function forPackage(int $packageId): array
    {
        $map = array_fill_keys(self::keys(), false);

        try {
            $rows = $this->db->select(
                'SELECT `feature` FROM `package_features` WHERE `package_id` = ?',
                [$packageId]
            );
        } catch (\Throwable $e) {
            return array_fill_keys(self::keys(), true);
        }

        if ($rows === []) {
            return array_fill_keys(self::keys(), true);
        }

        foreach ($rows as $row) {
            $key = strtolower((string) ($row['feature'] ?? ''));
            if (isset($map[$key])) {
                $map[$key] = true;
            }
        }

        return $map;
    }

    /** @return array<string,bool> */
    public function forAccount(array $account): array
    {
        return $this->forPackage((int) ($account['package_id'] ?? 0));
    }

    public function enabledForUser(?User $user, string $feature): bool
    {
        $feature = strtolower($feature);

        if (!isset(self::FEATURES[$feature])) {
            return true;
        }

        if ($user === null || $user->isAdmin) {
            return true;
        }

        try {
            $account = $this->db->selectOne(
                'SELECT * FROM `accounts` WHERE `user_id` = ?',
                [$user->id]
            );
        } catch (\Throwable $e) {
            return true;
        }

        if ($account === null) {
            return true;
        }

        $map = $this->forAccount($account);

        return $map[$feature] ?? true;
    }

    /**
     * @throws ForbiddenException when the user's package disables the feature
     */
    public function require(string $feature): void
    {
        if (!$this->enabledForUser(app('auth')->user(), $feature)) {
            throw new ForbiddenException(
                self::label($feature) . ' is not included in your hosting package.'
            );
        }
    }

    /**
     * Service-layer gate: same rule as require(), but keyed off an explicit
     * account row instead of the logged-in user, so worker jobs, installers
     * and provisioning services enforce the identical contract outside any
     * HTTP request. Admin intent is expressed by the caller passing
     * $asAdmin (admin panel controllers); everything else enforces.
     *
     * @throws ForbiddenException when the account's package disables the feature
     */
    public function requireForAccount(array $account, string $feature, bool $asAdmin = false): void
    {
        if ($asAdmin) {
            return;
        }
        $feature = strtolower($feature);
        if (!isset(self::FEATURES[$feature])) {
            return;
        }
        if (!($this->forAccount($account)[$feature] ?? true)) {
            throw new ForbiddenException(
                self::label($feature) . ' is not included in this hosting package.'
            );
        }
    }

    /**
     * Replace a package's feature set. Unknown keys are dropped so a stale
     * form post can never enable a non-catalog feature.
     *
     * @param array<int,string> $enabledKeys
     */
    public function setForPackage(int $packageId, array $enabledKeys): void
    {
        $valid = [];
        foreach ($enabledKeys as $key) {
            $key = strtolower(trim((string) $key));
            if (isset(self::FEATURES[$key])) {
                $valid[$key] = true;
            }
        }

        $this->db->delete('package_features', '`package_id` = ?', [$packageId]);
        foreach (array_keys($valid) as $key) {
            $this->db->insert('package_features', [
                'package_id' => $packageId,
                'feature' => $key,
            ]);
        }
    }

    /**
     * Strip nav items (and children) whose module is disabled for the user.
     * Group parents left childless with no URL of their own are dropped too.
     */
    public function filterNav(array $tree, ?User $user): array
    {
        $out = [];

        foreach ($tree as $item) {
            $module = strtolower((string) ($item['module'] ?? ''));
            if ($module !== '' && isset(self::FEATURES[$module])
                && !$this->enabledForUser($user, $module)
            ) {
                continue;
            }

            if (!empty($item['children'])) {
                $item['children'] = $this->filterNav($item['children'], $user);
                if ($item['children'] === [] && !isset($item['url'])) {
                    continue;
                }
            }

            $out[] = $item;
        }

        return $out;
    }
}
