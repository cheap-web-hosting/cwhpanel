<?php

declare(strict_types=1);

namespace Modules\AppInstaller\Services\Installer;

/**
 * @internal Extracted from InstallerService (concern group).
 */
trait SetupStepsTrait
{


    /**
     * Validated home-relative data dirs for a manifest (setup.datadirs
     * with {dir} for the install subdirectory, 'root' at domain root).
     *
     * @return array<int,string>
     */
    private function setupDataDirs(array $manifest, string $directory): array
    {
        $out = [];
        $datadirs = $manifest['setup']['datadirs'] ?? null;
        if (!is_array($datadirs)) {
            return $out;
        }
        foreach ($datadirs as $raw) {
            $rel = strtr((string) $raw, [
                '{home}' => '', '{dir}' => $directory !== '' ? $directory : 'root',
            ]);
            $rel = trim($rel, '/');
            if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
                continue;
            }
            $out[] = $rel;
        }
        return array_values(array_unique($out));
    }


    /**
     * Refuse a root install whose website cannot satisfy the manifest's
     * php_settings (root installs never auto-pin, so nothing else would
     * apply them). Compares each declared setting against the site's
     * effective value (row merged over OPTIONS defaults): text values by
     * magnitude (memory/time suffixes), flags by equality. Throws with
     * exact guidance; unknown keys fail closed upstream already.
     */
    /**
     * Refuse an install whose manifest PHP needs exceed the package ceilings.
     * Package caps are enforced as clamps, so installing anyway would fail
     * mid-run (or serve a broken app) instead of stating the fix up front.
     */
    private function assertPhpCapFit(array $manifest, array $account, string $appName): void
    {
        $want = is_array($manifest['php_settings'] ?? null) ? $manifest['php_settings'] : [];
        if ($want === []) {
            return;
        }
        $limits = app(\Modules\Core\Services\PhpVersionsService::class)->packageLimits($account);
        if ($limits['memory_limit_mb'] > 0 && isset($want['memory_limit'])) {
            $bytes = \Modules\Core\Services\PhpVersionsService::memoryToBytes((string) $want['memory_limit']);
            if ($bytes === null || $bytes < 0 || $bytes > $limits['memory_limit_mb'] * 1048576) {
                throw new \RuntimeException(
                    $appName . ' needs a higher PHP memory limit than your package allows ('
                    . $limits['memory_limit_mb'] . ' MB). Ask your administrator to raise the package cap.'
                );
            }
        }
        if ($limits['max_execution_time'] > 0 && isset($want['max_execution_time'])) {
            $secs = (int) $want['max_execution_time'];
            if ($secs > $limits['max_execution_time']) {
                throw new \RuntimeException(
                    $appName . ' needs a longer PHP execution time than your package allows ('
                    . $limits['max_execution_time'] . ' s). Ask your administrator to raise the package cap.'
                );
            }
        }
    }

    private function assertRootPhpSettings(array $manifest, array $account, string $docroot, string $appName): void
    {
        $want = [];
        try {
            $want = app(\Modules\Websites\Services\PhpDirService::class)->normalizeSettings(
                is_array($manifest['php_settings'] ?? null) ? $manifest['php_settings'] : []
            );
        } catch (\Throwable $e) {
            return;
        }
        if ($want === []) {
            return;
        }
        $site = app('db')->selectOne(
            'SELECT `php_settings` FROM `websites`
              WHERE `account_id` = ? AND `document_root` = ? AND `status` = ?
              ORDER BY `updated_at` DESC, `id` DESC LIMIT 1',
            [(int) ($account['id'] ?? 0), $docroot, 'active']
        );
        $siteSettings = [];
        if (is_string($site['php_settings'] ?? null) && $site['php_settings'] !== '') {
            $decoded = json_decode((string) $site['php_settings'], true);
            if (is_array($decoded)) {
                $siteSettings = $decoded;
            }
        }
        $options = \Modules\Core\Services\PhpVersionsService::OPTIONS;
        $missing = [];
        foreach ($want as $key => $need) {
            $have = array_key_exists($key, $siteSettings)
                ? (string) $siteSettings[$key]
                : (string) ($options[$key][2] ?? '');
            $type = (string) ($options[$key][1] ?? 'text');
            $ok = $type === 'onoff'
                ? $have === (string) $need
                : self::phpMagnitude((string) $need) <= 0 || self::phpMagnitude($have) >= self::phpMagnitude((string) $need);
            if (!$ok) {
                $missing[] = "{$key}={$need}";
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException(
                $appName . ' needs these PHP settings on the website to install at its root (' . implode(', ', $missing) . ')'
                . ' — set them under Websites → PHP Settings for the site, then reinstall.'
            );
        }
    }


    /**
     * Magnitude of a php.ini size/time value (256M, 1G, 300) in base units.
     * Unknown shapes return 0 so comparisons fail open toward guidance
     * only when the need itself is unparseable (fail-closed is handled by
     * normalizeSettings upstream).
     */
    private static function phpMagnitude(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(\d+)\s*([KMG])?$/i', $value, $m) !== 1) {
            return 0;
        }
        $mult = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824, '' => 1];
        return (int) $m[1] * ($mult[strtoupper($m[2] ?? '')] ?? 1);
    }


    /**
     * Highest installed PHP two-digit id within [min, max] (either bound
     * may be ''). Null when nothing installed satisfies the range.
     */
    private function compatiblePhpId(string $min, string $max): ?string
    {
        try {
            $installed = app(\Modules\Core\Services\PhpVersionsService::class)->installed();
        } catch (\Throwable $e) {
            return null;
        }
        $best = null;
        $bestVer = '';
        foreach ($installed as $id => $label) {
            $id = (string) $id; // PHP casts numeric-string keys to int.
            if (!preg_match('/^\d{2}$/', $id)) {
                continue;
            }
            $ver = substr($id, 0, 1) . '.' . substr($id, 1, 1);
            if ($min !== '' && version_compare($ver, $min, '<')) {
                continue;
            }
            if ($max !== '' && version_compare($ver, $max, '>')) {
                continue;
            }
            if ($best === null || version_compare($ver, $bestVer, '>')) {
                $best = $id;
                $bestVer = $ver;
            }
        }
        return $best;
    }


    /**
     * Placeholder map shared by setup.files / setup.cli interpolation:
     * {db_name} {db_user} {db_pass} {db_host} {prefix} {user} {pass}
     * {pass_hash} {email} {title} {url} {origin} {domain} {scheme} {home}
     * {dir} {webroot} {scriptpath} {php}.
     *
     * @return array<string,string>
     */
    private function setupPlaceholders(
        string $dbName,
        string $dbUser,
        string $dbPass,
        array $conf,
        array $manifest,
        string $title,
        string $user,
        string $pass,
        string $email,
        string $siteUrl,
        string $domain,
        string $username,
        string $directory,
        array $account,
        string $sitePhpId,
        array $opts
    ): array {
        $scheme = (string) ($opts['scheme'] ?? 'https');
        $siteDir = $directory !== '' ? '/' . $directory : '';
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        // Absolute install path (Elgg's --config needs it: the agent runs
        // setup scripts without cd-ing into the tree).
        $docroot = rtrim((string) ($opts['docroot'] ?? ''), '/');
        // Braced keys so callers can strtr() directly.
        return [
            '{db_host}' => (string) env('DB_HOST', '127.0.0.1'),
            '{db_name}' => $dbName,
            '{db_user}' => $dbUser,
            '{db_pass}' => $dbPass,
            '{prefix}' => (string) ($conf['prefix'] ?? ($manifest['database']['prefix'] ?? '')),
            '{title}' => $title,
            '{user}' => $user,
            '{pass}' => $pass,
            '{pass_hash}' => ($hash === false ? '' : $hash),
            '{app_key}' => 'base64:' . base64_encode(random_bytes(32)),
            '{email}' => $email,
            '{url}' => $siteUrl,
            '{origin}' => $scheme . '://' . $domain,
            '{domain}' => $domain,
            '{scheme}' => $scheme,
            '{home}' => (string) ($account['home_dir'] ?? '/home/' . $username),
            '{dir}' => $directory !== '' ? $directory : 'root',
            '{dest}' => $docroot . $siteDir,
            // URL path with trailing slash ('/shop/', '/' at root — Cacti's
            // url_path needs exactly this form).
            '{urlpath}' => rtrim($siteDir, '/') . '/',
            '{webroot}' => $siteDir !== '' ? $siteDir : '/',
            '{scriptpath}' => $siteDir,
            '{php}' => $sitePhpId,
        ];
    }


    /**
     * Run post-setup UPDATE statements over a transient PDO as the fresh DB
     * user. Each entry: {table, set:{col:value}, where:{col:value}}; values
     * support {user}/{email}/{title}/{date}. Column names are validated.
     *
     * @param array<string,mixed> $updates
     * @param array<string,string> $map
     */
    private function runSqlPost(string $dbName, string $dbUser, string $dbPass, string $prefix, array $updates, array $map): void
    {
        $pdo = new \PDO(
            "mysql:host=" . (string) env('DB_HOST', '127.0.0.1') . ";dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        foreach ($updates as $u) {
            if (!is_array($u)) {
                continue;
            }
            $table = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', (string) ($u['table'] ?? ''));
            $set = [];
            foreach ((array) ($u['set'] ?? []) as $col => $val) {
                if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $col)) {
                    throw new \RuntimeException('bad set column');
                }
                $set[] = "`{$col}` = " . $pdo->quote(strtr((string) $val, $map));
            }
            $where = [];
            foreach ((array) ($u['where'] ?? []) as $col => $val) {
                if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $col)) {
                    throw new \RuntimeException('bad where column');
                }
                $where[] = "`{$col}` = " . $pdo->quote(strtr((string) $val, $map));
            }
            if ($table === '' || $set === []) {
                continue;
            }
            $sql = "UPDATE `{$table}` SET " . implode(', ', $set) . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');
            $pdo->exec($sql);
        }
    }


    /**
     * Create an app's recommended cron rows (setup.cron: [{schedule,
     * command}] with {placeholders}) as ordinary panel cron jobs and sync
     * the account crontab once. Respects the cron feature gate and the
     * package count limit; skips duplicates; never throws (all failures
     * become install-log warnings).
     *
     * @param array<string,string> $vals
     * @return array<int,string> created command strings
     */
    private function setupAppCron(int $installationId, array $account, array $specs, array $vals, string $dbPass, string $adminPass): array
    {
        $created = [];
        try {
            app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'cron');
        } catch (\Throwable $e) {
            $this->log($installationId, 'warning', 'Recommended cron jobs skipped (cron feature unavailable on this package).');
            return $created;
        }
        $accountId = (int) ($account['id'] ?? 0);
        $map = $vals;
        foreach ($specs as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            try {
                $schedule = \Modules\Cron\Services\CronService::assertSingleLine((string) ($spec['schedule'] ?? ''), 'schedule');
                $command = \Modules\Cron\Services\CronService::assertSingleLine(strtr((string) ($spec['command'] ?? ''), $map), 'command');
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', 'Skipped an invalid cron entry (' . $e->getMessage() . ').');
                continue;
            }
            if (strlen($schedule) > 191 || strlen($command) > 255 || $command === '') {
                $this->log($installationId, 'warning', 'Skipped an oversized cron entry.');
                continue;
            }
            // Belt-and-braces: cron rows persist forever, so a manifest
            // mistake must never smuggle a credential into one.
            foreach ([$dbPass, $adminPass] as $secret) {
                if ($secret !== '' && str_contains($command, $secret)) {
                    $this->log($installationId, 'warning', 'Skipped a cron entry that would persist a secret.');
                    continue 2;
                }
            }
            try {
                $exists = app('db')->selectOne(
                    'SELECT `id` FROM `cron_jobs` WHERE `account_id` = ? AND `command` = ?',
                    [$accountId, $command]
                );
                if ($exists !== null) {
                    $created[] = $command;
                    continue;
                }
                \Modules\Packages\Services\LimitService::require($accountId, 'cron');
                $id = (int) app('db')->insert('cron_jobs', [
                    'account_id' => $accountId,
                    'schedule' => $schedule,
                    'command' => $command,
                    'status' => 'enabled',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                audit('cron.created', 'cron_job', $id, ['via' => 'appinstaller', 'installation' => $installationId]);
                $this->log($installationId, 'info', "Cron job added ({$schedule}): {$command}");
                $created[] = $command;
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', 'Could not add a cron job (' . $e->getMessage() . ') — add it under Cron if needed.');
            }
        }
        if ($created !== []) {
            try {
                app(\Modules\Cron\Services\CronService::class)->sync($accountId);
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', 'Cron jobs stored but the crontab did not sync (' . $e->getMessage() . ') — open Cron and save to retry.');
            }
        }
        return $created;
    }


    /**
     * Apply an app's WAF exclusions (setup.waf: {exclude: [ruleIds]}) to the
     * mount website and return the rule IDs this install added (pre-existing
     * exclusions are left alone AND unrecorded, so uninstall never removes
     * a hand-made row). Capped (a manifest must not gut the WAF), validated,
     * never throws (all failures become install-log warnings).
     *
     * @return array<int,int> added rule IDs
     */
    private function setupAppWaf(int $installationId, array $account, string $domain, array $exclude, string $appName): array
    {
        $added = [];
        $ids = [];
        foreach ($exclude as $raw) {
            $id = \Modules\Security\Services\WafService::validateRuleId($raw);
            if ($id === null) {
                $this->log($installationId, 'warning', 'Skipped an invalid WAF rule ID in the app manifest.');
                continue;
            }
            $ids[$id] = true;
        }
        if ($ids === []) {
            return $added;
        }
        if (count($ids) > 10) {
            $this->log($installationId, 'warning', 'An app may exclude at most 10 WAF rules — extras ignored.');
            $ids = array_slice($ids, 0, 10, true);
        }
        $siteRow = app('db')->selectOne(
            'SELECT w.`id` FROM `websites` w
              JOIN `domains` d ON d.`id` = w.`domain_id`
              WHERE w.`account_id` = ? AND d.`name` = ? AND w.`status` = ?
              ORDER BY w.`updated_at` DESC, w.`id` DESC LIMIT 1',
            [(int) ($account['id'] ?? 0), $domain, 'active']
        );
        if ($siteRow === null) {
            $this->log($installationId, 'warning', 'Skipped WAF exclusions (no website on this domain yet).');
            return $added;
        }
        $websiteId = (int) $siteRow['id'];
        $waf = app(\Modules\Security\Services\WafService::class);
        $existing = [];
        foreach ($waf->exclusions($websiteId) as $e) {
            $existing[(int) $e] = true;
        }
        foreach (array_keys($ids) as $ruleId) {
            if (isset($existing[$ruleId])) {
                continue;
            }
            try {
                $waf->addExclusion($websiteId, $ruleId, "Required by {$appName} (one-click install)");
                $this->log($installationId, 'info', "WAF rule {$ruleId} excluded for this website ({$appName} needs it).");
                $added[] = $ruleId;
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', "Could not exclude WAF rule {$ruleId} (" . $e->getMessage() . ') — add it under Websites → WAF if needed.');
            }
        }
        return $added;
    }


    /**
     * Manifest-driven CLI setup: one or more steps, each running the tree's
     * own installer binary (occ, maintenance/install.php, index_cli.php)
     * with {placeholders} interpolated into discrete argv elements (no
     * shell) and executed as the account user. Output tail lands in the
     * install log; failures degrade to the browser wizard (never fatal).
     *
     * Manifest shape: setup.cli = {script, args[], php_version?} or a list
     * of such steps (e.g. install + config:set follow-ups). A step may carry
     * skip_if {placeholder: value} to run conditionally (e.g. subdir-only
     * webroot overrides).
     *
     * @param array<string,string> $vals
     */
    private function runCliSetup(int $installationId, string $username, string $destRel, array $cli, array $vals, string $appName): void
    {
        $steps = isset($cli['script']) ? [$cli] : (array_is_list($cli) ? $cli : []);
        if ($steps === []) {
            return;
        }
        // Keys already carry braces (setupPlaceholders) — strtr directly.
        $map = $vals;
        foreach ($steps as $i => $step) {
            if (!is_array($step) || !isset($step['script']) || !isset($step['args']) || !is_array($step['args'])) {
                continue;
            }
            // Conditional step (e.g. subdir-only webroot override).
            $skip = false;
            foreach ((array) ($step['skip_if'] ?? []) as $k => $v) {
                if (array_key_exists((string) $k, $vals) && (string) $vals[(string) $k] === (string) $v) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $argv = [];
            // Secrets run in the child's environment, never in argv (no
            // `ps` exposure). Only drivers that OPT IN (manifest
            // `setup.secrets_env`) get this treatment: they read CWH_SETUP_*
            // env instead of the argv slot. Stock upstream CLIs (occ, artisan,
            // bin/console …) and discrete-argv drivers (FreshRSS) need the
            // real value on argv — scrubbing their slots broke them.
            $envSecrets = [];
            $useEnvSecrets = !empty($manifest['setup']['secrets_env']);
            foreach ($step['args'] as $raw) {
                $one = strtr((string) $raw, $map);
                if (strlen($one) > 2048) {
                    $this->log($installationId, 'warning', 'Automatic setup did not finish (oversized argument) — open the site URL to finish setup in the browser.');
                    return;
                }
                $trimmed = trim((string) $raw);
                if ($useEnvSecrets && $trimmed === '{pass}') {
                    $envSecrets['CWH_SETUP_PASS'] = $one;
                    $argv[] = '';
                    continue;
                }
                if ($useEnvSecrets && $trimmed === '{db_pass}') {
                    $envSecrets['CWH_SETUP_DB_PASS'] = $one;
                    $argv[] = '';
                    continue;
                }
                if ($useEnvSecrets && $trimmed === '{pass_hash}') {
                    $envSecrets['CWH_SETUP_PASS_HASH'] = $one;
                    $argv[] = '';
                    continue;
                }
                // JSON-blob arg (e.g. OpenEMR/Gitea/Forgejo drivers): strip
                // secret keys so they never hit argv, send them via env.
                if ($useEnvSecrets && str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                    $decoded = json_decode($one, true);
                    if (is_array($decoded)) {
                        foreach (['pass' => 'CWH_SETUP_PASS', 'db_pass' => 'CWH_SETUP_DB_PASS'] as $key => $envKey) {
                            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                                $envSecrets[$envKey] = $decoded[$key];
                                unset($decoded[$key]);
                            }
                        }
                        $argv[] = json_encode($decoded, JSON_UNESCAPED_SLASHES);
                        continue;
                    }
                }
                $argv[] = $one;
            }
            try {
                $opArgs = [
                    'account' => $username,
                    'dest' => $destRel,
                    'script' => (string) $step['script'],
                    'args' => json_encode($argv),
                ];
                if ($envSecrets !== []) {
                    $opArgs['secrets'] = json_encode($envSecrets);
                }
                if (isset($step['php_version'])) {
                    $pv = strtr((string) $step['php_version'], $map);
                    if (preg_match('/^\d{2}$/', $pv)) {
                        $opArgs['php_version'] = $pv;
                    }
                } elseif (isset($vals['{php}']) && preg_match('/^\d{2}$/', (string) $vals['{php}'])) {
                    // Default: the app's own (possibly pinned) PHP version,
                    // not the system interpreter.
                    $opArgs['php_version'] = (string) $vals['{php}'];
                }
                $r = app('provisioning')->execute('installer.php.run', $opArgs);
                $out = trim((string) ($r['output'] ?? ''));
                $tail = $out !== '' ? mb_substr($out, -500) : '(no output)';
                $n = count($steps) > 1 ? ' step ' . ((int) $i + 1) . '/' . count($steps) : '';
                $this->log($installationId, 'info', "{$appName} setup{$n} finished: {$tail}");
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', 'Automatic setup did not finish (' . $e->getMessage() . ') — open the site URL to finish setup in the browser.');
                return;
            }
        }
    }


    /**
     * Declarative SQL setup: import the shipped schema with a table-prefix
     * swap (agent op, root-socket mysql — no secrets transit), insert the
     * admin row over a transient PDO as the fresh DB user, and remove the
     * installer dirs. Failures degrade to the browser wizard.
     *
     * Manifest shape:
     *   setup.sql = {schema, prefix_from?, admin?{table,user_col,pass_col,
     *     email_col?,salt_col?,hash,extra?{col: template}}, cleanup?[]}
     * Hash kinds: bcrypt, md5, sha1, mybb (md5(md5(salt).md5(pass))),
     *   joomla (bcrypt + random id handled by caller default).
     *
     * @param array<string,string> $vals
     */
    private function runSqlSetup(int $installationId, string $username, string $destRel, array $sqlSetup, array $vals, string $siteUrl, string $appName): void
    {
        $prefix = (string) ($vals['prefix'] ?? '');
        if ($vals['db_name'] === '') {
            $this->log($installationId, 'warning', 'Automatic setup did not finish (missing database) — open the site URL to finish setup in the browser.');
            return;
        }
        // One or more schema files (Joomla ships base.sql + extensions.sql).
        $schemas = $sqlSetup['schemas'] ?? null;
        if (!is_array($schemas)) {
            $schemas = [$sqlSetup['schema'] ?? ''];
        }
        foreach ($schemas as $schemaFile) {
            $schemaFile = (string) $schemaFile;
            if ($schemaFile === '') {
                continue;
            }
            try {
                $importArgs = [
                    'account' => $username,
                    'dest' => $destRel,
                    'schema' => $schemaFile,
                    'from_prefix' => (string) ($sqlSetup['prefix_from'] ?? ''),
                    'to_prefix' => $prefix,
                    'database' => (string) $vals['db_name'],
                ];
                if (!empty($sqlSetup['tokens']) && is_array($sqlSetup['tokens'])) {
                    $importArgs['tokens'] = json_encode($sqlSetup['tokens']);
                }
                app('provisioning')->execute('installer.db.import', $importArgs);
                $this->log($installationId, 'info', 'Database tables created from ' . basename($schemaFile) . '.');
            } catch (\Throwable $e) {
                // Re-runs meet existing tables (ERROR 1050): declarative and
                // safe to consider done in that case.
                if (!str_contains($e->getMessage(), '1050')) {
                    $this->log($installationId, 'warning', 'Automatic setup did not finish (' . $e->getMessage() . ') — open the site URL to finish setup in the browser.');
                    return;
                }
                $this->log($installationId, 'info', basename($schemaFile) . ' already present.');
            }
        }

        $adminId = 0;
        $admin = $sqlSetup['admin'] ?? null;
        if (is_array($admin) && isset($admin['table'], $admin['user_col'], $admin['pass_col'], $admin['hash'])) {
            try {
                $adminId = $this->insertSqlAdmin($vals, $prefix, $admin);
                // Related rows (Joomla user_usergroup_map): inserted with the
                // admin id just created ({admin_id} placeholder).
                foreach ((array) ($sqlSetup['related'] ?? []) as $rel) {
                    $this->insertSqlRow($vals, $prefix, $rel, $adminId);
                }
                $this->log($installationId, 'info', "{$appName} is ready — log in with '{$vals['user']}' at {$siteUrl}");
            } catch (\Throwable $e) {
                // Duplicate login on a re-run means the first attempt
                // already created it: treat as done, not as failure.
                if (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate')) {
                    $this->log($installationId, 'info', "{$appName} is ready — log in with '{$vals['user']}' at {$siteUrl}");
                } else {
                    $this->log($installationId, 'warning', 'Tables are in, but the admin account failed (' . $e->getMessage() . ') — create it in the browser wizard.');
                    return;
                }
            }
        } else {
            $this->log($installationId, 'info', "{$appName} is ready — open {$siteUrl}");
            return;
        }

        foreach ($sqlSetup['cleanup'] ?? [] as $rel) {
            $rel = trim((string) $rel, '/');
            if ($rel === '' || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
                continue;
            }
            try {
                app('provisioning')->execute('directory.remove', [
                    'account' => $username,
                    'path' => $destRel === '' ? $rel : $destRel . '/' . $rel,
                ]);
            } catch (\Throwable $e) {
                $this->log($installationId, 'warning', "Could not remove installer dir {$rel} (remove it manually): " . $e->getMessage());
            }
        }
    }


    /**
     * Insert the admin row for a declarative SQL setup over a transient PDO
     * as the fresh database user (credentials live only in this call).
     *
     * @param array<string,string> $vals
     */
    private function insertSqlAdmin(array $vals, string $prefix, array $admin): int
    {
        $table = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', (string) ($admin['table'] ?? ''));
        foreach (['user_col', 'pass_col'] as $k) {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) ($admin[$k] ?? ''))) {
                throw new \RuntimeException('bad admin column spec');
            }
        }
        $emailCol = isset($admin['email_col']) && preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $admin['email_col'])
            ? (string) $admin['email_col'] : null;
        $saltCol = isset($admin['salt_col']) && preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $admin['salt_col'])
            ? (string) $admin['salt_col'] : null;
        [$hash, $salt] = $this->hashAdminPassword((string) ($admin['hash'] ?? 'bcrypt'), (string) $vals['pass']);
        $cols = [(string) $admin['user_col'] => (string) $vals['user'], (string) $admin['pass_col'] => $hash];
        if ($emailCol !== null) {
            $cols[$emailCol] = (string) $vals['email'];
        }
        if ($saltCol !== null) {
            $cols[$saltCol] = $salt;
        }
        foreach ((array) ($admin['extra'] ?? []) as $col => $tpl) {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $col)) {
                throw new \RuntimeException('bad admin extra column');
            }
            $cols[(string) $col] = strtr((string) $tpl, [
                '{user}' => (string) $vals['user'],
                '{email}' => (string) $vals['email'],
                '{title}' => (string) $vals['title'],
                '{url}' => (string) $vals['url'],
                '{date}' => date('Y-m-d H:i:s'),
            ]);
        }
        $pdo = new \PDO(
            "mysql:host=" . (string) $vals['db_host'] . ";dbname=" . (string) $vals['db_name'] . ";charset=utf8mb4",
            (string) $vals['db_user'],
            (string) $vals['db_pass'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $names = implode(',', array_map(static fn ($c) => "`{$c}`", array_keys($cols)));
        $place = implode(',', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$place})");
        $stmt->execute(array_values($cols));
        return (int) $pdo->lastInsertId();
    }


    /**
     * Insert one related row (Joomla user_usergroup_map) over a transient
     * PDO as the fresh DB user. `{admin_id}` expands to the admin id just
     * created; only validated column names ever reach the statement.
     *
     * @param array<string,string> $vals
     * @param array<string,mixed> $rel
     */
    private function insertSqlRow(array $vals, string $prefix, array $rel, int $adminId): void
    {
        $table = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', (string) ($rel['table'] ?? ''));
        $cols = [];
        foreach ((array) ($rel['cols'] ?? []) as $col => $tpl) {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $col)) {
                throw new \RuntimeException('bad related column');
            }
            $cols[(string) $col] = strtr((string) $tpl, ['{admin_id}' => (string) $adminId]);
        }
        if ($table === '' || $cols === []) {
            return;
        }
        $pdo = new \PDO(
            "mysql:host=" . (string) $vals['db_host'] . ";dbname=" . (string) $vals['db_name'] . ";charset=utf8mb4",
            (string) $vals['db_user'],
            (string) $vals['db_pass'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $names = implode(',', array_map(static fn ($c) => "`{$c}`", array_keys($cols)));
        $place = implode(',', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$place})");
        $stmt->execute(array_values($cols));
    }


    /**
     * Hash an admin password for a declarative SQL setup.
     *
     * @return array{0:string,1:string} [hash, salt]
     */
    private function hashAdminPassword(string $kind, string $pass): array
    {
        switch (strtolower($kind)) {
            case 'bcrypt':
            case 'joomla':
                $h = password_hash($pass, PASSWORD_BCRYPT);
                if ($h === false) {
                    throw new \RuntimeException('bcrypt failed');
                }
                return [$h, ''];
            case 'md5':
                return [md5($pass), ''];
            case 'sha1':
                return [sha1($pass), ''];
            case 'mybb':
                $salt = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
                return [md5(md5($salt) . md5($pass)), $salt];
            case 'opencart3':
                // sha1(salt . sha1(salt . sha1(pass))) with a random salt.
                $salt = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 9);
                return [sha1($salt . sha1($salt . sha1($pass))), $salt];
            default:
                throw new \RuntimeException("unknown admin hash [{$kind}]");
        }
    }
}
