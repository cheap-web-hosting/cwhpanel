<?php

declare(strict_types=1);

namespace Modules\AppInstaller\Services\Installer;

/**
 * @internal Extracted from InstallerService (concern group).
 */
trait UninstallTrait
{


    public function uninstall(int $installationId, bool $removeFiles, bool $removeDb): void
    {
        $inst = app('db')->selectOne('SELECT * FROM `ai_installations` WHERE `id` = ?', [$installationId]);
        if ($inst === null) {
            throw new \RuntimeException('Installation not found.');
        }
        $account = app('db')->selectOne('SELECT * FROM `accounts` WHERE `id` = ?', [(int) $inst['account_id']]);
        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }

        $username = $account['username'];
        $home = $account['home_dir'] ?? "/home/{$username}";
        $path = (string) $inst['path'];

        // Confinement: the install path must be inside the account home.
        if (!str_starts_with($path, $home)) {
            throw new \RuntimeException('Install path escapes account home.');
        }

        // App-type installs own a supervised process row: stop the unit and
        // drop the row first (frees the port + proxy mount), then remove
        // files below like any other install.
        $websiteId = 0;
        if (!empty($inst['application_id'])) {
            $appRow = app('db')->selectOne(
                'SELECT * FROM `applications` WHERE `id` = ? AND `account_id` = ?',
                [(int) $inst['application_id'], (int) $account['id']]
            );
            if ($appRow !== null) {
                try {
                    app('provisioning')->execute('app.stop', [
                        'account' => $username, 'name' => $appRow['name'],
                    ]);
                } catch (\Throwable $e) {
                    $this->log($installationId, 'info', 'Stop skipped: ' . $e->getMessage());
                }
                $websiteId = (int) ($appRow['website_id'] ?? 0);
                app('db')->delete('applications', '`id` = ?', [(int) $appRow['id']]);
            }
        }

        // Remove files via agent (confined relative path). SAFETY: never
        // delete a domain document root itself — a root-level install
        // (directory = '') records path == docroot, and rm -rf on it would
        // wipe the vhost target + .well-known (bitten live on myownhost.ovh:
        // uninstalling WordPress deleted public_html entirely). Instead,
        // empty the directory contents and leave the dir + vhost intact.
        // App-subdir installs (~/apps/<slug>, subdirs) remove normally.
        if ($removeFiles) {
            $rel = ltrim(str_replace($home, '', $path), '/');
            // Single-file installs (TiddlyWiki, Adminer…): the recorded path
            // is the file, not a directory. A subdir install created its own
            // folder beside the file — remove that folder; a root file
            // install removes only the file (never the docroot).
            $app = $this->catalog->find((string) $inst['application_slug']);
            $isFileInstall = $app !== null
                && (($this->catalog->manifest($app)['download']['format'] ?? '') === 'file');
            if ($isFileInstall) {
                if ((string) $inst['directory'] !== '') {
                    $dirRel = ltrim(str_replace($home, '', dirname($path)), '/');
                    $dirAbs = rtrim(dirname($path), '/');
                    if (!$this->isDomainDocroot((int) $account['id'], $dirAbs)) {
                        app('provisioning')->execute('directory.remove', ['account' => $username, 'path' => $dirRel]);
                    } else {
                        app('provisioning')->execute('file.delete', ['account' => $username, 'path' => $rel]);
                    }
                } else {
                    app('provisioning')->execute('file.delete', ['account' => $username, 'path' => $rel]);
                }
            } else {
                $isDocroot = $this->isDomainDocroot((int) $account['id'], $path);
                if ($isDocroot) {
                    $this->emptyDirectory($username, $home, $rel, $installationId, (int) $account['id']);
                } else {
                    app('provisioning')->execute('directory.remove', ['account' => $username, 'path' => $rel]);
                }
            }
            // Outside-tree data dirs (Nextcloud ncdata-*): same remove-files
            // switch as the install tree — user data goes with the app.
            try {
                $unApp = $this->catalog->find((string) $inst['application_slug']);
                if ($unApp !== null) {
                    foreach ($this->setupDataDirs($this->catalog->manifest($unApp), (string) ($inst['directory'] ?? '')) as $dataRel) {
                        try {
                            app('provisioning')->execute('directory.remove', ['account' => $username, 'path' => $dataRel]);
                            $this->log($installationId, 'info', "Removed data directory ~/{$dataRel}");
                        } catch (\Throwable $e) {
                            $this->log($installationId, 'warning', "Could not remove ~/{$dataRel} (" . $e->getMessage() . ')');
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal: orphaned data dirs are removable by hand.
            }
        }
        if ($removeDb && !empty($inst['db_name'])) {
            app(\Modules\Databases\Services\DatabaseService::class)->delete((string) $inst['db_name']);
        }

        // Flip to uninstalled BEFORE the vhost re-renders below: the
        // generators only see installs whose status != 'uninstalled', so
        // flipping late re-emits the removed app's location block (bitten
        // live — a koel-b9 alias survived its own uninstall). The row
        // itself is kept as history; $inst above stays usable.
        app('db')->update('ai_installations', [
            'status' => 'uninstalled',
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$installationId]);

        // Remove cron rows this install created (recorded in its config):
        // they point at the install tree and would otherwise fire forever.
        try {
            $cfg = json_decode((string) ($inst['config'] ?? '{}'), true);
            $cmds = is_array($cfg['cron_commands'] ?? null) ? array_values($cfg['cron_commands']) : [];
            foreach ($cmds as $cmd) {
                if (!is_string($cmd) || $cmd === '') {
                    continue;
                }
                $row = app('db')->selectOne(
                    'SELECT `id` FROM `cron_jobs` WHERE `account_id` = ? AND `command` = ?',
                    [(int) $account['id'], $cmd]
                );
                if ($row !== null) {
                    app(\Modules\Cron\Services\CronService::class)->deleteJob((int) $account['id'], (int) $row['id']);
                    $this->log($installationId, 'info', "Removed cron job: {$cmd}");
                }
            }
        } catch (\Throwable $e) {
            $this->log($installationId, 'warning', 'Could not remove cron jobs (' . $e->getMessage() . ')');
        }

        // Remove WAF exclusions this install added (recorded in its config):
        // only IDs the install itself created are dropped — anything already
        // excluded (by hand or by another install) stays untouched.
        try {
            $cfg = json_decode((string) ($inst['config'] ?? '{}'), true);
            $rules = is_array($cfg['waf_exclusions'] ?? null) ? array_values($cfg['waf_exclusions']) : [];
            if ($rules !== []) {
                $siteRow = app('db')->selectOne(
                    'SELECT w.`id` FROM `websites` w
                      JOIN `domains` d ON d.`id` = w.`domain_id`
                      WHERE w.`account_id` = ? AND d.`name` = ? AND w.`status` = ?
                      ORDER BY w.`updated_at` DESC, w.`id` DESC LIMIT 1',
                    [(int) $account['id'], (string) $inst['domain'], 'active']
                );
                if ($siteRow !== null) {
                    $waf = app(\Modules\Security\Services\WafService::class);
                    foreach ($rules as $ruleId) {
                        $ruleId = \Modules\Security\Services\WafService::validateRuleId($ruleId);
                        if ($ruleId === null) {
                            continue;
                        }
                        try {
                            $waf->removeExclusion((int) $siteRow['id'], $ruleId);
                            $this->log($installationId, 'info', "Re-enabled WAF rule {$ruleId}.");
                        } catch (\Throwable $e) {
                            $this->log($installationId, 'warning', "Could not re-enable WAF rule {$ruleId} (" . $e->getMessage() . ')');
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log($installationId, 'warning', 'Could not clean up WAF exclusions (' . $e->getMessage() . ')');
        }

        // Drop a per-directory PHP pin this install created: a row the
        // installer added carries no custom settings (it only pins the
        // version), so removing it restores the site default without
        // touching anything the user configured by hand.
        $instDir = trim((string) ($inst['directory'] ?? ''), '/');
        if ($instDir !== '') {
            try {
                $siteRow = app('db')->selectOne(
                    'SELECT w.`id`, w.`php_version` FROM `websites` w
                      JOIN `domains` d ON d.`id` = w.`domain_id`
                      WHERE w.`account_id` = ? AND d.`name` = ? AND w.`status` = ?
                      ORDER BY w.`updated_at` DESC, w.`id` DESC LIMIT 1',
                    [(int) $account['id'], (string) $inst['domain'], 'active']
                );
                if ($siteRow !== null) {
                    $pinRow = app('db')->selectOne(
                        'SELECT * FROM `website_php_dirs` WHERE `website_id` = ? AND `dir` = ?',
                        [(int) $siteRow['id'], $instDir]
                    );
                    $pinSettings = is_string($pinRow['settings'] ?? null) && $pinRow['settings'] !== ''
                        ? (json_decode((string) $pinRow['settings'], true) ?: [])
                        : [];
                    // Remove the pin only when WE created it: version-only
                    // pins (legacy behavior) or pins whose settings exactly
                    // match the manifest's php_settings (e.g. Dolphin's
                    // allow_url_include=0, which would otherwise strand the
                    // pin — and its vhost block — on every uninstall).
                    // Hand-customized pins (anything else) stay untouched.
                    $removePin = $pinSettings === [];
                    if (!$removePin) {
                        try {
                            $pinApp = $this->catalog->find((string) $inst['application_slug']);
                            $manifestSettings = $pinApp !== null
                                ? ($this->catalog->manifest($pinApp)['php_settings'] ?? [])
                                : [];
                            $wantSettings = is_array($manifestSettings)
                                ? app(\Modules\Websites\Services\PhpDirService::class)->normalizeSettings($manifestSettings)
                                : [];
                            $removePin = ($pinSettings === $wantSettings);
                        } catch (\Throwable $e) {
                            $removePin = false;
                        }
                    }
                    if ($pinRow !== null && $removePin
                        && (string) ($pinRow['php_version'] ?? '') !== ''
                        && (string) ($pinRow['php_version'] ?? '') !== (string) ($siteRow['php_version'] ?? '')
                    ) {
                        app(\Modules\Websites\Services\PhpDirService::class)->delete(
                            (int) $siteRow['id'],
                            (int) $pinRow['id'],
                            true
                        );
                        $this->log($installationId, 'info', "Removed the /{$instDir}/ PHP version pin.");
                    }
                    // Subdirectory installs own a front-controller location
                    // in the vhost: re-render so it disappears with the app
                    // (PhpDirService::delete already pushed when it removed
                    // a pin; this covers the no-pin case).
                    app(\Modules\Websites\Services\PrivacyService::class)->regenerate((int) $siteRow['id']);
                }
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', 'Could not refresh website after PHP dir cleanup (' . $e->getMessage() . ')');
            }
        }

        $this->log($installationId, 'info', 'Uninstalled' . ($removeFiles ? ' (files removed)' : ' (files kept)') . ($removeDb ? ' + database dropped' : ''));

        // Re-render the mount website so the proxy location disappears.
        // (Direct regenerate: refreshProxy()'s logging targets the kept
        // history row, which is fine — logs survive the uninstall.)
        if ($websiteId > 0) {
            try {
                app(\Modules\Websites\Services\PrivacyService::class)->regenerate($websiteId);
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', 'Could not regenerate mount website (' . $e->getMessage() . ')');
            }
        }
    }


    /**
     * Is $path a document root of one of the account's domains (or websites)?
     * Used by uninstall() to refuse rm -rf on a vhost target. The WEBSITES
     * table is the authoritative serving-path source — a row created via the
     * scratch recipe (or any drift between domains.document_root and the
     * website row) must still be protected. Without this, a root install
     * whose docroot wasn't mirrored into domains.document_root was rm -rf'd
     * whole on uninstall (bitten live at the root+subdomain sweep: the ENTIRE
     * main docroot — and with it every nested subdomain site — vanished).
     */
    private function isDomainDocroot(int $accountId, string $path): bool
    {
        $norm = rtrim($path, '/');
        if ($norm === '') {
            return false;
        }
        $candidates = [];
        try {
            foreach (app('db')->select('SELECT `document_root` FROM `domains` WHERE `account_id` = ?', [$accountId]) as $r) {
                $candidates[] = $r['document_root'];
            }
        } catch (\Throwable $e) {
            // fall through
        }
        try {
            foreach (app('db')->select('SELECT `document_root` FROM `websites` WHERE `account_id` = ?', [$accountId]) as $r) {
                $candidates[] = $r['document_root'];
            }
        } catch (\Throwable $e) {
            // fall through
        }
        foreach ($candidates as $docroot) {
            $docroot = rtrim((string) $docroot, '/');
            if ($docroot !== '' && $docroot === $norm) {
                return true;
            }
        }
        return false;
    }


    /**
     * Empty a directory's CONTENTS via the agent (files + subdirs), leaving
     * the directory itself in place. Lists via file.list, deletes each child
     * by relative path. Best-effort per child: one stuck entry must not abort
     * the rest; failures are logged to the installation log.
     *
     * SAFETY: children that are themselves another website's document root
     * (nested subdomains of a main domain — /home/<user>/public_html/<sub>/)
     * are SKIPPED. A root-app uninstall on a parent site must never delete
     * the trees of sibling sites that live beneath its docroot; that was
     * silent data loss for subdomain customers (proven at the root+subdomain
     * sweep: uninstalling a root app on sweep.test wiped sub.sweep.test).
     */
    private function emptyDirectory(string $username, string $home, string $rel, int $installationId, int $accountId): void
    {
        // Nested website document roots under this docroot (skip these).
        $protected = [];
        if ($accountId > 0) {
            try {
                foreach (app('db')->select(
                    'SELECT `document_root` FROM `websites` WHERE `account_id` = ? AND `status` = ? AND `document_root` IS NOT NULL AND `document_root` != ?',
                    [$accountId, 'active', '']
                ) as $p) {
                    $protected[rtrim((string) $p['document_root'], '/')] = true;
                }
            } catch (\Throwable $e) {
                // best effort
            }
        }
        try {
            $listing = app(\Modules\Files\Services\FileService::class)->list($username, $rel);
        } catch (\Throwable $e) {
            $this->log($installationId, 'warning', 'Could not list directory for emptying: ' . $e->getMessage());
            return;
        }
        $entries = $listing['entries'] ?? $listing ?? [];
        if (!is_array($entries)) {
            return;
        }
        $homeNorm = rtrim($home, '/');
        foreach ($entries as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $child = trim($rel . '/' . $name, '/');
            $childAbs = rtrim($homeNorm . '/' . $child, '/');
            if (isset($protected[$childAbs])) {
                $this->log($installationId, 'info', 'Kept nested website docroot ~/' . $child);
                continue;
            }
            try {
                app('provisioning')->execute('directory.remove', ['account' => $username, 'path' => $child]);
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', 'Could not remove ' . $child . ': ' . $e->getMessage());
            }
        }
    }
}
