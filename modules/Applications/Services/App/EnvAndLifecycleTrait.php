<?php

declare(strict_types=1);

namespace Modules\Applications\Services\App;

/**
 * Environment variables, dependency refresh, staging clone/promotion, and
 * webhook deploy helpers for AppService.
 */
trait EnvAndLifecycleTrait
{
    /**
     * Validate one env key. Mirrors the agent's charset exactly (uppercase,
     * leading alpha, <=64 chars) plus the PORT/HOST reservation the agent
     * cannot know about.
     */
    public static function normalizeEnvKey(string $key): string
    {
        $key = strtoupper(trim($key));
        if ($key === '' || strlen($key) > 64 || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new \RuntimeException("Invalid env key [{$key}]: A-Z, 0-9 and _ only, leading letter.");
        }
        if (in_array($key, self::ENV_RESERVED, true)) {
            throw new \RuntimeException("{$key} is managed automatically and cannot be set.");
        }
        return $key;
    }

    /** Validate one env value. Mirrors the agent's shell-safe charset. */
    public static function normalizeEnvValue(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 256 || preg_match('/^[A-Za-z0-9_.\-:\/]+$/', $value) !== 1) {
            throw new \RuntimeException('Invalid env value: letters, digits and _.-:/ only (max 256).');
        }
        return $value;
    }

    /** Parse the stored env column into KEY => VALUE. */
    public static function parseEnv(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,]+/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            if ($k !== '') {
                $out[$k] = trim($v);
            }
        }
        return $out;
    }

    /**
     * Replace an app's env vars from textarea text (one K=V per line).
     * Takes effect on the next start/restart (units read environment at
     * exec). Line numbers in errors so big pastes stay debuggable.
     */
    public function saveEnvLines(array $account, array $app, string $text, bool $asAdmin = false): void
    {
        $vars = [];
        $n = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $n++;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new \RuntimeException("Line {$n}: expected KEY=value.");
            }
            [$key, $value] = explode('=', $line, 2);
            try {
                $key = self::normalizeEnvKey($key);
                $value = self::normalizeEnvValue($value);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException("Line {$n}: " . $e->getMessage());
            }
            $vars[$key] = $value;
        }
        $this->saveEnv($account, $app, $vars, $asAdmin);
    }

    /**
     * Replace an app's env vars (one K=V per line in $vars). Takes effect
     * on the next start/restart (units read environment at exec).
     */
    public function saveEnv(array $account, array $app, array $vars, bool $asAdmin = false): void
    {
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications', $asAdmin);
        $lines = [];
        foreach ($vars as $key => $value) {
            $key = self::normalizeEnvKey((string) $key);
            $value = self::normalizeEnvValue((string) $value);
            $lines[$key] = $key . '=' . $value;
        }
        ksort($lines);
        app('db')->update('applications', [
            'env' => $lines === [] ? null : implode("\n", $lines),
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [(int) $app['id']]);
        audit('app.env.saved', 'application', (int) $app['id'], ['keys' => array_keys($lines)]);
    }

    /** Refresh an app's dependencies from its manifest (node_modules / requirements). */
    public function refreshDeps(array $account, array $app): string
    {
        $runtime = (string) ($app['runtime'] ?? '');
        if ($runtime === 'node') {
            try {
                app('provisioning')->execute('file.read', [
                    'account' => $account['username'],
                    'path' => 'apps/' . $app['name'] . '/package.json',
                ]);
            } catch (\Throwable $e) {
                return 'skipped: no package.json';
            }
            $this->npmInstallDir($account, $app);
            return 'npm install from package.json';
        }
        if ($runtime === 'python') {
            try {
                app('provisioning')->execute('file.read', [
                    'account' => $account['username'],
                    'path' => 'apps/' . $app['name'] . '/requirements.txt',
                ]);
            } catch (\Throwable $e) {
                return 'skipped: no requirements.txt';
            }
            $this->ensureVenv($account, $app);
            app('provisioning')->execute('python.requirements.install', [
                'account' => $account['username'],
                'name' => $app['name'],
            ]);
            return 'pip install -r requirements.txt';
        }
        throw new \RuntimeException('Unknown runtime.');
    }

    /**
     * Full update: git pull (when remote) + dependency refresh + restart
     * when running. Runs inside the app.update worker job. Returns a
     * human summary for the job result.
     */
    public function updateApp(array $account, array $app, bool $asAdmin = false): string
    {
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications', $asAdmin);
        $steps = [];
        if (!empty($app['git_url'])) {
            $this->deploy($account, $app, $asAdmin);
            $steps[] = 'git fetch+reset';
        } else {
            $steps[] = 'git skipped (no remote)';
        }
        $steps[] = 'deps: ' . $this->refreshDeps($account, $app);
        if ($this->isRunning($account, $app)) {
            $this->stop($account, $app);
            usleep(300000);
            $this->start($account, $app, $asAdmin);
            $steps[] = 'restarted';
        } else {
            $steps[] = 'left stopped';
        }
        app('db')->update('applications', ['updated_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int) $app['id']]);
        audit('app.updated', 'application', (int) $app['id']);
        return implode('; ', $steps);
    }

    /**
     * Clone an app as staging: new row + copied work dir (minus .venv and
     * node_modules — rebuilt by start/update, and venv shebangs would
     * point at the old app), fresh port when the original had one, same
     * env/git, no proxy mount (mounts belong to production), stopped.
     * Returns the new application id.
     */
    public function cloneApp(array $account, array $app, string $newName, bool $asAdmin = false): int
    {
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications', $asAdmin);
        $username = (string) $account['username'];
        $newName = trim($newName);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $newName)) {
            throw new \RuntimeException('Invalid app name (letters, digits, _ and -).');
        }
        $taken = app('db')->selectOne(
            'SELECT `id` FROM `applications` WHERE `account_id` = ? AND `name` = ?',
            [(int) $account['id'], $newName]
        );
        if ($taken !== null) {
            throw new \RuntimeException('An app with that name already exists.');
        }
        $prov = app('provisioning');
        $newDir = "/home/{$username}/apps/{$newName}";
        $srcDir = (string) $app['work_dir'];
        if ($srcDir !== "/home/{$username}/apps/" . $app['name']) {
            throw new \RuntimeException('Only standard-layout apps can be cloned.');
        }
        try {
            $prov->execute('file.copy', [
                'account' => $username,
                'from' => 'apps/' . $app['name'],
                'to' => 'apps/' . $newName,
            ]);
        } catch (\Throwable $e) {
            try { $prov->execute('directory.remove', ['account' => $username, 'path' => 'apps/' . $newName]); } catch (\Throwable $ignored) {}
            throw $e;
        }
        foreach (['.venv', 'node_modules'] as $drop) {
            try {
                $prov->execute('directory.remove', ['account' => $username, 'path' => 'apps/' . $newName . '/' . $drop]);
            } catch (\Throwable $ignored) {}
        }
        $prov->execute('directory.chown', ['owner' => $username, 'path' => $newDir]);
        $port = null;
        if (!empty($app['port'])) {
            $port = self::allocatePort();
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) app('db')->insert('applications', [
            'account_id' => (int) $account['id'],
            'name' => $newName,
            'runtime' => (string) $app['runtime'],
            'runtime_version' => $app['runtime_version'] ?? null,
            'entry_point' => (string) $app['entry_point'],
            'work_dir' => $newDir,
            'status' => 'stopped',
            'port' => $port,
            'website_id' => null,
            'base_path' => null,
            'env' => $app['env'] ?? null,
            'git_url' => $app['git_url'] ?? null,
            'git_branch' => $app['git_branch'] ?? 'main',
            'cloned_from' => (int) $app['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        audit('app.cloned', 'application', $id, ['from' => (int) $app['id']]);
        return $id;
    }

    /**
     * Promote staging files to production: replace the parent work dir
     * with a copy of the staging dir (minus dependency trees — the
     * parent's venv/node_modules survive, run Update for fresh deps).
     * Files only; nothing restarts (restart to apply).
     */
    public function syncToProduction(array $account, array $staging, bool $asAdmin = false): void
    {
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications', $asAdmin);
        $parentId = (int) ($staging['cloned_from'] ?? 0);
        if ($parentId <= 0) {
            throw new \RuntimeException('Only staging clones can sync (no production parent).');
        }
        $parent = app('db')->selectOne('SELECT * FROM `applications` WHERE `id` = ?', [$parentId]);
        if ($parent === null || (int) $parent['account_id'] !== (int) $account['id']) {
            throw new \RuntimeException('Production app not found in this account.');
        }
        $username = (string) $account['username'];
        $prov = app('provisioning');
        $home = "/home/{$username}/";
        $stagingDir = (string) $staging['work_dir'];
        $parentDir = (string) $parent['work_dir'];
        foreach ([$stagingDir, $parentDir] as $d) {
            if (!str_starts_with($d, $home . 'apps/') || str_contains($d, '..')) {
                throw new \RuntimeException('Refusing to sync (non-standard work dir).');
            }
        }
        $stagingRel = substr($stagingDir, strlen($home));
        $parentRel = substr($parentDir, strlen($home));
        if ($stagingRel === $parentRel) {
            throw new \RuntimeException('Refusing to sync (identical work dirs).');
        }
        $tmpRel = 'apps/.stgsync-' . (int) $parent['id'];
        try { $prov->execute('directory.remove', ['account' => $username, 'path' => $tmpRel]); } catch (\Throwable $ignored) {}
        $prov->execute('file.copy', ['account' => $username, 'from' => $stagingRel, 'to' => $tmpRel]);
        foreach (['.venv', 'node_modules'] as $drop) {
            try {
                $prov->execute('directory.remove', ['account' => $username, 'path' => $tmpRel . '/' . $drop]);
            } catch (\Throwable $ignored) {}
        }
        $prov->execute('directory.chown', ['owner' => $username, 'path' => $home . $tmpRel]);
        $prov->execute('directory.remove', ['account' => $username, 'path' => $parentRel]);
        try {
            $prov->execute('file.copy', ['account' => $username, 'from' => $tmpRel, 'to' => $parentRel]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Sync copy failed; production files are intact inside ' . $tmpRel . ' — copy them back manually. ' . $e->getMessage());
        }
        $prov->execute('directory.chown', ['owner' => $username, 'path' => $parentDir]);
        try { $prov->execute('directory.remove', ['account' => $username, 'path' => $tmpRel]); } catch (\Throwable $ignored) {}
        audit('app.synced', 'application', (int) $parent['id'], ['from' => (int) $staging['id']]);
    }

    /**
     * Public push-to-deploy URL for an app. Lives under /api/ so the global
     * CSRF gate skips it (external git forges cannot carry a panel token);
     * authentication is the per-app HMAC secret instead.
     */
    public static function webhookUrl(int $appId): string
    {
        return url('/api/hooks/apps/' . $appId);
    }

    /** Mint (or replace) the app's webhook secret. Returns the new secret. */
    public function rotateWebhookSecret(int $appId): string
    {
        $secret = bin2hex(random_bytes(32));
        app('db')->update('applications', [
            'webhook_secret' => $secret,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = ?', [$appId]);
        return $secret;
    }

    /**
     * Verify an inbound git-forge delivery against the app secret. Accepts
     * GitHub's HMAC-SHA256 signature OR GitLab's token header (auto-detect
     * by which header is present). Returns the provider name or null.
     * Constant-time compares throughout; empty secrets never verify.
     */
    public static function verifyWebhookSignature(string $secret, string $rawBody, ?string $githubSig, ?string $gitlabToken): ?string
    {
        if ($secret === '') {
            return null;
        }
        if ($githubSig !== null && $githubSig !== '') {
            $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
            return hash_equals($expected, $githubSig) ? 'github' : null;
        }
        if ($gitlabToken !== null && $gitlabToken !== '') {
            return hash_equals($secret, $gitlabToken) ? 'gitlab' : null;
        }
        return null;
    }

    /**
     * Queue an async deploy for an app (the worker runs JobRegistry's
     * app.deploy handler). Deduplicates: a second delivery while one is
     * still pending returns deduped=true instead of stacking work —
     * forges routinely send several events per push.
     *
     * @return array{queued:bool, deduped:bool}
     */
    public function queueDeploy(int $appId, string $source): array
    {
        $pending = app('db')->selectOne(
            "SELECT `id` FROM `jobs` WHERE `type` = ? AND `status` = ?
             AND (`payload` LIKE ? OR `payload` LIKE ?) LIMIT 1",
            ['app.deploy', 'pending', '%"application_id":' . $appId . ',%', '%"application_id":' . $appId . '}']
        );
        if ($pending !== null) {
            return ['queued' => false, 'deduped' => true];
        }
        app('db')->insert('jobs', [
            'type' => 'app.deploy',
            'status' => 'pending',
            'payload' => json_encode(['application_id' => $appId, 'source' => $source]),
            'result' => null,
            'attempts' => 0,
            'max_attempts' => 2,
            'available_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        audit('app.deploy.queued', 'application', $appId, ['source' => $source]);
        return ['queued' => true, 'deduped' => false];
    }
}
