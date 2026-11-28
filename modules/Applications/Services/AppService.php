<?php
declare(strict_types=1);
namespace Modules\Applications\Services;

/**
 * Backs applications with real processes via the privileged agent:
 * start/stop/restart/status/logs delegate to a per-account systemd unit
 * (cwh-app-<account>-<name>) running under the account's UID + cgroup slice.
 */
class AppService
{
    use \Modules\Applications\Services\App\EnvAndLifecycleTrait;

    /** Reserved env keys: auto-managed at start, never user-settable. */
    public const ENV_RESERVED = ['PORT', 'HOST'];

    /**
     * Runtimes selectable per app. Keys are the only values the agent maps
     * to binaries — anything else is rejected before dispatch.
     */
    public const RUNTIMES = [
        'node' => ['26' => 'Node.js 26', '22' => 'Node.js 22 LTS', '20' => 'Node.js 20 LTS'],
        'python' => ['3.9' => 'Python 3.9', '3.11' => 'Python 3.11', '3.12' => 'Python 3.12'],
    ];

    /**
     * pip packages installable into an app venv, name => blurb. Wheel-only:
     * account users cannot reach a compiler (root-only gcc/make by design),
     * so anything without prebuilt wheels would fail — the agent additionally
     * passes --only-binary to fail fast instead of attempting a build.
     * Mirrored in the agent allowlist (validate_python_package).
     */
    public const PYTHON_PACKAGES = [
        'gunicorn' => 'WSGI server for Flask/Django apps',
        'uvicorn' => 'ASGI server for FastAPI/Starlette apps',
        'flask' => 'Micro web framework',
        'django' => 'Full-stack web framework',
        'fastapi' => 'Modern API framework',
        'requests' => 'HTTP library',
        'pillow' => 'Image processing',
        'numpy' => 'Numerical computing',
        'sqlalchemy' => 'SQL toolkit + ORM',
        'psycopg2-binary' => 'PostgreSQL adapter (wheels)',
        'redis' => 'Redis client',
        'celery' => 'Distributed task queue',
        'httpx' => 'Async-capable HTTP client',
        'beautifulsoup4' => 'HTML/XML scraping',
        'lxml' => 'Fast XML/HTML processing',
        'pyyaml' => 'YAML parser',
    ];

    /**
     * pip packages for catalog APP installs (python-pip type). Separate from
     * PYTHON_PACKAGES (user-chosen UI installs): these come only from
     * admin-approved catalog manifests. Mirrored in the agent allowlist
     * (validate_python_app_package) + the python.app.install op.
     *
     * All entries are small pure-Python servers launchable with zero/low
     * config (console script + host/port args, optionally with seed files).
     */
    public const PYTHON_APP_PACKAGES = [
        'radicale', 'notebook', 'xandikos', 'trac', 'reviewboard',
        'kallithea', 'octoprint', 'sabnzbd', 'pyload', 'timetagger',
        'datasette', 'mkdocs', 'pypiserver', 'isso', 'esphome', 'wsgidav',
    ];

    /** Reverse-proxy port range reserved for catalog/proxied apps. */
    public const APP_PORT_MIN = 22000;
    public const APP_PORT_MAX = 22999;

    /**
     * Allocate a stable reverse-proxy port for an app (unique index +
     * retry; NULL means no HTTP ingress). Ports survive restarts and are
     * freed when the applications row is deleted.
     */
    public static function allocatePort(): int
    {
        $db = app('db');
        for ($try = 0; $try < 25; $try++) {
            $port = random_int(self::APP_PORT_MIN, self::APP_PORT_MAX);
            $taken = $db->selectOne('SELECT `id` FROM `applications` WHERE `port` = ?', [$port]);
            if ($taken === null) {
                return $port;
            }
        }
        throw new \RuntimeException('No free application port (range exhausted).');
    }

    /**
     * Install a catalog app package into the app venv (python-pip type).
     * Synchronous — the installer worker calls this (never user input:
     * $package must be an admin-approved manifest value).
     */
    public function installAppPackage(array $account, array $app, string $package): void
    {
        $package = strtolower(trim($package));
        if (!in_array($package, self::PYTHON_APP_PACKAGES, true)) {
            throw new \RuntimeException('Unknown Python app package.');
        }
        $this->ensureVenv($account, $app);
        app('provisioning')->execute('python.app.install', [
            'account' => $account['username'],
            'name' => $app['name'],
            'package' => $package,
        ]);
    }

    /** npm install a catalog package into the app dir (node-npm type). */
    public function npmInstall(array $account, array $app, string $package, ?string $nodeVersion = null): void
    {
        app('provisioning')->execute('app.npm.install', [
            'account' => $account['username'],
            'name' => $app['name'],
            'package' => $package,
            'version' => self::normalizeVersion('node', $nodeVersion ?? ($app['runtime_version'] ?? null)),
        ]);
    }

    /** Bare `npm install` from the dir's own package.json (node-archive). */
    public function npmInstallDir(array $account, array $app, ?string $nodeVersion = null): void
    {
        app('provisioning')->execute('app.npm.install', [
            'account' => $account['username'],
            'name' => $app['name'],
            'version' => self::normalizeVersion('node', $nodeVersion ?? ($app['runtime_version'] ?? null)),
        ]);
    }

    /**
     * Write the generated entry-point launcher into the app dir (overwrites
     * any previous one). Two shapes, both bare filenames (the runner rejects
     * entry points containing '/'):
     *   python — subprocess-exec .venv/bin/<script> (+ baked argv). Console
     *     scripts work exactly as designed (correct sys.argv[0], no runpy
     *     import games); the system interpreter runs them with the venv's
     *     site-packages on PYTHONPATH (same model as app.start).
     *   node — require() of the baked bin path (+ baked argv).
     *   binary — subprocess-exec ./<file> (+ baked argv) for single-binary
     *     servers (Go/Rust releases); the python wrapper only bootstraps.
     * {PORT}/{HOST} in args resolve to the app's allocated loopback port.
     *
     * @return string the entry filename to store on the applications row
     */
    public function writeLauncher(array $account, array $app, string $runtime, string $target, array $args = []): string
    {
        $port = (string) ((int) ($app['port'] ?? 0) > 0 ? (int) $app['port'] : '');
        // {PORT}/{HOST} in args resolve to the app's allocated port and bind
        // host. Under L-14 app-netns the bind host is the account's namespace
        // address (so the app is reachable from the host at 10.213.x.y and
        // from no other account); otherwise loopback.
        // Fail closed: when app-network isolation is enabled the launcher may
        // only be written with the namespace address (audit #20).
        $bindHost = app(\Modules\Network\Services\AppNetnsService::class)->ensureHost($account);
        $sub = function (string $s) use ($port, $bindHost): string {
            return str_replace(['{PORT}', '{HOST}'], [$port, $bindHost], $s);
        };
        $argv = [];
        foreach (array_values($args) as $a) {
            $argv[] = var_export($sub((string) $a), true);
        }
        if ($runtime === 'python') {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $target) || str_contains($target, '..')) {
                throw new \RuntimeException('Invalid venv script.');
            }
            $content = "import subprocess, sys, os\n"
                . 'os.execv(sys.executable, [sys.executable, ".venv/bin/' . $target . '"' . ($argv !== [] ? ', ' . implode(', ', $argv) : '') . "])\n";
            $file = 'cwhpanel_entry.py';
        } elseif ($runtime === 'node') {
            if (!preg_match('#^[A-Za-z0-9_.@/\-]+$#', $target) || str_contains($target, '..')) {
                throw new \RuntimeException('Invalid node bin path.');
            }
            $content = 'process.argv.push(...' . json_encode(array_values(array_map($sub, $args))) . ");\n"
                // Dynamic import, not require(): modern bins are pure ESM
                // (json-server v1 fatals under require() with
                // ERR_REQUIRE_ASYNC_MODULE), while CJS bins import fine
                // through the same call. A failed import exits loud with
                // the reason instead of hanging the unit silent.
                . "import('./" . str_replace("'", "", $target) . "').catch((e) => { console.error(e && e.message ? e.message : e); process.exit(1); });\n";
            $file = 'cwhpanel_entry.js';
        } elseif ($runtime === 'binary') {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $target) || str_contains($target, '..')) {
                throw new \RuntimeException('Invalid binary name.');
            }
            $content = "import subprocess, sys, os\n"
                // Forge apps (gitea/forgejo) locate their own binary through
                // PATH at startup (hook scripts) and silently exit 1 when it
                // is unresolvable — the supervised unit's minimal PATH has no
                // workdir. Prepend it (account-scoped, launcher-local).
                . "_here = os.path.dirname(os.path.abspath(__file__))\n"
                . "os.environ[\"PATH\"] = _here + os.pathsep + os.environ.get(\"PATH\", \"\")\n"
                . 'os.execv("./' . $target . '", ["' . $target . '"' . ($argv !== [] ? ', ' . implode(', ', $argv) : '') . "])\n";
            $file = 'cwhpanel_entry.py';
        } else {
            throw new \RuntimeException('Unknown launcher runtime.');
        }
        $rel = 'apps/' . $app['name'] . '/' . $file;
        $ok = app(\Modules\Files\Services\FileService::class)->write($account['username'], $rel, $content);
        if (!$ok) {
            throw new \RuntimeException('Could not write launcher.');
        }
        return $file;
    }

    public static function defaultVersion(string $runtime): string
    {
        return $runtime === 'python' ? '3.9' : '26';
    }

    /** Validated version or the runtime default (unknown values never reach the agent). */
    public static function normalizeVersion(string $runtime, ?string $version): string
    {
        $version = trim((string) $version);
        if (isset(self::RUNTIMES[$runtime][$version])) {
            return $version;
        }
        return self::defaultVersion($runtime);
    }

    /**
     * Validate app source fields before they reach the agent (git fetch +
     * supervised start). Returns an error string, or null when clean.
     * - entry_point: relative basename only (no /, no ..) with a runtime
     *   file extension, so start() can never be pointed at an absolute
     *   path or a traversal target.
     * - git_url (optional): https:// or ssh/scp-like syntax only; no
     *   file://, no credentials-bearing or private/loopback hosts.
     * - git_branch: safe charset, no .., no leading dash/slash (git would
     *   treat those as flags/paths).
     */
    public static function validateSource(string $runtime, string $entryPoint, ?string $gitUrl, ?string $gitBranch): ?string
    {
        $entryPoint = trim($entryPoint);
        if ($entryPoint === '' || str_contains($entryPoint, '/') || str_contains($entryPoint, '..')) {
            return 'Entry point must be a file name inside the app directory (no paths).';
        }
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $entryPoint) !== 1) {
            return 'Entry point contains invalid characters.';
        }
        $ext = strtolower(pathinfo($entryPoint, PATHINFO_EXTENSION));
        $allowed = $runtime === 'python' ? ['py'] : ['js', 'mjs', 'cjs'];
        if (!in_array($ext, $allowed, true)) {
            return 'Entry point must be a .' . implode('/.', $allowed) . ' file for ' . $runtime . ' apps.';
        }
        $branch = trim((string) $gitBranch);
        if ($branch === '') {
            return 'Git branch is required.';
        }
        if (strlen($branch) > 64 || preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) !== 1
            || str_contains($branch, '..') || $branch[0] === '-' || $branch[0] === '/') {
            return 'Git branch contains invalid characters.';
        }
        $url = trim((string) $gitUrl);
        if ($url === '') {
            return null;
        }
        if (strlen($url) > 255 || preg_match('/\s/', $url) === 1) {
            return 'Git URL is invalid.';
        }
        if (str_starts_with($url, 'https://')) {
            $host = (string) parse_url($url, PHP_URL_HOST);
        } elseif (preg_match('/^(?:[A-Za-z0-9_.-]+@)?([A-Za-z0-9_.-]+):[^:]*$/', $url, $m) === 1 && !str_contains($url, '://')) {
            // scp-like syntax: [user@]host:path
            $host = $m[1];
        } elseif (str_starts_with($url, 'ssh://')) {
            $host = (string) parse_url($url, PHP_URL_HOST);
        } else {
            return 'Git URL must use https:// or SSH (user@host:path / ssh://).';
        }
        $host = strtolower($host);
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'Git host is not allowed.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'Git host must be a public address.';
        }
        return null;
    }

    /**
     * Installed runtimes, probed by binary presence: ['node' => [...], 'python' => [...]].
     * Used to gate install buttons and validate selections.
     */
    public function installedRuntimes(): array
    {
        $out = ['node' => [], 'python' => []];
        $this->runtimeProbeErrors = [];

        // Node runtimes live outside the panel's open_basedir view (/usr/local,
        // /opt) — presence comes from the agent's root-side inventory op.
        $nodePaths = ['26' => '/usr/local/bin/node', '22' => '/opt/node-22/bin/node', '20' => '/opt/node-20/bin/node'];
        try {
            $r = app('provisioning')->execute('node.versions.exact');
            $lines = preg_split('/\r\n|\r|\n/', trim((string) ($r['output'] ?? ''))) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/^(\S+)\s+v?([0-9]+\.[0-9]+\.[0-9]+)$/', trim($line), $m) !== 1) {
                    continue;
                }
                foreach ($nodePaths as $v => $bin) {
                    if ($m[1] === $bin) {
                        $out['node'][] = (string) $v;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->runtimeProbeErrors[] = 'node inventory: ' . $e->getMessage();
        }

        foreach (['3.9' => '/usr/bin/python3', '3.11' => '/usr/bin/python3.11', '3.12' => '/usr/bin/python3.12'] as $v => $bin) {
            if (!$this->binaryIsFile($bin)) {
                continue; // runtime not installed — not an error
            }
            try {
                $r = app('provisioning')->execute('app.runtime.probe', ['path' => $bin]);
                if (trim((string) ($r['output'] ?? '')) !== '') {
                    $out['python'][] = (string) $v;
                }
            } catch (\Throwable $e) {
                $this->runtimeProbeErrors[] = $bin . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

    /** is_file() guarded — open_basedir restrictions must never throw here. */
    private function binaryIsFile(string $path): bool
    {
        try {
            return is_file($path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Per-path probe errors from the last installedRuntimes() call ('' = ok). */
    private array $runtimeProbeErrors = [];

    public function runtimeProbeErrors(): array
    {
        return $this->runtimeProbeErrors;
    }

    /** Install a side-by-side runtime (node tarball / python dnf) via agent. */
    public function installRuntime(string $runtime, string $version, ?string $exact = null, ?string $sha256 = null): void
    {
        $args = ['runtime' => $runtime, 'version' => $version];
        if ($exact !== null) {
            $args['exact'] = $exact;
        }
        if ($sha256 !== null) {
            $args['sha256'] = $sha256;
        }
        app('provisioning')->execute('app.runtime.install', $args);
    }

    /**
     * Resolve the latest LTS node release for a major: [exact, sha256].
     * Fetched live from nodejs.org (TLS); throws on any failure.
     *
     * @return array{0:string, 1:string}
     */
    public static function resolveNode(string $major): array
    {
        if (!in_array($major, ['20', '22', '26'], true)) {
            throw new \RuntimeException('Unsupported Node major.');
        }
        $ctx = stream_context_create(['http' => ['timeout' => 20]]);
        $index = @file_get_contents('https://nodejs.org/dist/index.json', false, $ctx);
        $rows = json_decode((string) $index, true);
        if (!is_array($rows)) {
            throw new \RuntimeException('Node release index unreachable.');
        }
        $exact = null;
        foreach ($rows as $row) {
            $ver = ltrim((string) ($row['version'] ?? ''), 'v');
            if (!preg_match('/^' . preg_quote($major, '/') . '\.\d+\.\d+$/', $ver)) {
                continue;
            }
            // 20/22 are LTS-only; 26 is the primary line and may be a stable
            // non-LTS release. The index is newest-first.
            if ($major !== '26' && empty($row['lts'])) {
                continue;
            }
            $exact = $ver;
            break;
        }
        if ($exact === null) {
            throw new \RuntimeException('No stable release found for Node ' . $major . '.');
        }
        $sums = @file_get_contents("https://nodejs.org/dist/v{$exact}/SHASUMS256.txt", false, $ctx);
        if (!is_string($sums) || !preg_match('/^([a-f0-9]{64})\s+node-v' . preg_quote($exact, '/') . '-linux-x64\.tar\.xz$/m', $sums, $m)) {
            throw new \RuntimeException('Node checksum unavailable for ' . $exact . '.');
        }
        return [$exact, strtolower($m[1])];
    }

    /**
     * Resolve the sha256 checksum for an exact node release (e.g. "26.9.0"),
     * fetched live from nodejs.org. Used by the vulnerability watch to apply a
     * node-finding (tarball runtime update) with a verified download.
     */
    public static function nodeChecksum(string $exact): string
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $exact)) {
            throw new \RuntimeException('Invalid exact node version.');
        }
        $ctx = stream_context_create(['http' => ['timeout' => 20]]);
        $sums = @file_get_contents("https://nodejs.org/dist/v{$exact}/SHASUMS256.txt", false, $ctx);
        if (!is_string($sums) || !preg_match('/^([a-f0-9]{64})\s+node-v' . preg_quote($exact, '/') . '-linux-x64\.tar\.xz$/m', $sums, $m)) {
            throw new \RuntimeException('Node checksum unavailable for ' . $exact . '.');
        }
        return strtolower($m[1]);
    }
    public function createWorkDir(string $account, string $appName, bool $asAdmin = false): string
    {
        if (!$asAdmin) {
            $row = app('db')->selectOne('SELECT * FROM `accounts` WHERE `username` = ?', [$account]);
            if ($row !== null) {
                app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($row, 'applications');
            }
        }
        $dir = "/home/{$account}/apps/{$appName}";
        app('provisioning')->execute('directory.create', ['path' => $dir]);
        app('provisioning')->execute('directory.chown', ['owner' => $account, 'path' => $dir]);
        return $dir;
    }

    /** Package memory ceiling for a runtime in MB (0 = inherit the slice cap). */
    private function packageMemoryMb(array $account, string $runtime): int
    {
        $column = match ($runtime) {
            'node' => 'max_node_memory_mb',
            'python' => 'max_python_memory_mb',
            default => null,
        };
        if ($column === null) {
            return 0;
        }
        try {
            return max(0, (int) app('db')->scalar(
                "SELECT `{$column}` FROM `packages` WHERE `id` = ?",
                [(int) ($account['package_id'] ?? 0)]
            ));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function start(array $account, array $app, bool $asAdmin = false): bool
    {
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications', $asAdmin);
        // A package that includes no Node.js / Python apps disables the runtime
        // outright: refuse start/restart, not just new app creation.
        $runtime = (string) ($app['runtime'] ?? '');
        if (!$asAdmin && \Modules\Packages\Services\LimitService::runtimeDisabled((int) ($account['id'] ?? 0), $runtime)) {
            throw new \RuntimeException(ucfirst($runtime) . ' apps are disabled for this package.');
        }
        // Python apps run from their own virtualenv when present; ensure one
        // exists for the app's selected base version (idempotent refresh, or
        // rebuild on version drift). Best-effort: a venv failure still starts
        // on the system interpreter. Only when a venv already exists:
        // binary-wrapper rows also carry runtime python, and an unconditional
        // ensure mints a stray empty venv in every binary app dir on every
        // start (proven live). Apps that need pip always get their venv at
        // install/package time, so there is always one to maintain here.
        if (($app['runtime'] ?? '') === 'python' && $this->hasVenv($account, $app)) {
            try {
                $this->ensureVenv($account, $app);
            } catch (\Throwable $e) {
                app('logger')->warning('venv ensure failed for ' . $account['username'] . '/' . $app['name'] . ': ' . $e->getMessage());
            }
        }
        $args = [
            'account' => $account['username'],
            'name' => $app['name'],
            'runtime' => $app['runtime'],
            'work_dir' => $app['work_dir'],
            'entry_point' => $app['entry_point'],
        ];
        // M2: when app jail mode is enabled, app runtimes execute inside the
        // account's mount-namespace jail (same hiding as cron jailed jobs).
        try {
            if ((string) app('db')->scalar("SELECT `value` FROM `settings` WHERE `key` = 'security.app_jail_enabled' LIMIT 1") === '1') {
                $args['jailed'] = '1';
            }
        } catch (\Throwable $e) {
        }
        if (!empty($app['runtime_version'])) {
            $args['runtime_version'] = $app['runtime_version'];
        }
        // Package runtime memory ceiling (0 = inherit the account slice).
        $memoryMb = $this->packageMemoryMb($account, (string) ($app['runtime'] ?? ''));
        if ($memoryMb > 0) {
            $args['memory_mb'] = $memoryMb;
        }
        // L-14: when app network namespaces are enabled, join the account's
        // namespace and bind the app to its namespace address so the host-side
        // nginx can proxy to it while no other account can reach it.
        // Fail closed: isolation enabled means the app joins its verified
        // namespace or does not start (audit #20).
        $netns = app(\Modules\Network\Services\AppNetnsService::class);
        $hostBind = $netns->ensureHost($account);
        if ($netns->enabled()) {
            $args['netns'] = '1';
        }

        // Reverse-proxied apps learn their port (and bind host) the
        // 12-factor way; every app additionally carries its stored env
        // (empty = no env arg, exactly like before).
        $env = [];
        if (!empty($app['port'])) {
            $env[] = 'PORT=' . (int) $app['port'];
            $env[] = 'HOST=' . $hostBind;
        }
        foreach (preg_split('/[\r\n,]+/', (string) ($app['env'] ?? '')) as $line) {
            $line = trim($line);
            // PORT/HOST are auto-managed above (and rejected by the env
            // editor); never let a stored duplicate make the unit ambiguous.
            if ($line !== '' && !preg_match('/^(PORT|HOST)=/i', $line)) {
                $env[] = $line;
            }
        }
        if ($env !== []) {
            $args['env'] = implode(',', array_unique($env));
        }
        $r = app('provisioning')->execute('app.start', $args);
        return $r['status'] === 'success';
    }

    /**
     * Ensure the app venv exists for the requested base version. A venv
     * built on another version is removed and recreated (packages do not
     * migrate — same rule as a local `rm -rf .venv` + rebuild).
     */
    public function ensureVenv(array $account, array $app): void
    {
        $want = self::normalizeVersion('python', $app['runtime_version'] ?? null);
        $rel = 'apps/' . $app['name'] . '/.venv/pyvenv.cfg';
        $have = null;
        try {
            $r = app('provisioning')->execute('file.read', [
                'account' => $account['username'],
                'path' => $rel,
            ]);
            $cfg = base64_decode((string) ($r['output'] ?? ''));
            if (preg_match('/^version\s*=\s*(\d+\.\d+)/m', (string) $cfg, $m)) {
                $have = $m[1];
            }
        } catch (\Throwable $e) {
            $have = null;
        }
        // $have is MAJOR.MINOR (the cfg regex strips the patch), same
        // shape as normalized $want — compare bases exactly. A
        // starts-with against "$want." NEVER matches ('3.11' does not
        // start with '3.11.') and wiped every venv on every start,
        // dropping all installed packages.
        if ($have !== null && $have === $want) {
            return; // same base (3.11 matches 3.11.x) — leave packages alone
        }
        if ($have !== null) {
            app('provisioning')->execute('directory.remove', [
                'account' => $account['username'],
                'path' => 'apps/' . $app['name'] . '/.venv',
            ]);
        }
        app('provisioning')->execute('app.venv.create', [
            'account' => $account['username'],
            'name' => $app['name'],
            'version' => $want,
        ]);
    }

    /** Whether the app has its own virtualenv (python dependency isolation). */
    public function hasVenv(array $account, array $app): bool
    {
        if (($app['runtime'] ?? '') !== 'python') {
            return false;
        }
        try {
            app('provisioning')->execute('app.venv.status', [
                'account' => $account['username'],
                'name' => $app['name'],
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Validated catalog name or throw (unknown values never reach pip). */
    public static function normalizePackage(string $package): string
    {
        $package = strtolower(trim($package));
        if (!isset(self::PYTHON_PACKAGES[$package])) {
            throw new \RuntimeException('Unknown Python package.');
        }
        return $package;
    }

    /**
     * Installed packages in the app venv: [name => version] parsed from
     * `pip list --format=freeze`. Empty when there is no venv yet.
     */
    public function installedPackages(array $account, array $app): array
    {
        if (($app['runtime'] ?? '') !== 'python' || !$this->hasVenv($account, $app)) {
            return [];
        }
        try {
            $r = app('provisioning')->execute('python.packages.list', [
                'account' => $account['username'],
                'name' => $app['name'],
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach (explode("\n", (string) ($r['output'] ?? '')) as $line) {
            $line = trim($line);
            if (preg_match('/^([A-Za-z0-9_.\-]+)==([^\s]+)$/', $line, $m)) {
                $out[strtolower($m[1])] = $m[2];
            }
        }
        return $out;
    }

    /**
     * Install a catalog package into the app venv (ensuring the venv
     * first, same as start() does). Synchronous — callers queue it as a
     * `python.package.install` job instead.
     */
    public function installPackage(array $account, array $app, string $package): void
    {
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications');
        if (($app['runtime'] ?? '') !== 'python') {
            throw new \RuntimeException('Packages are only available for Python apps.');
        }
        $package = self::normalizePackage($package);
        $this->ensureVenv($account, $app);
        app('provisioning')->execute('python.packages.install', [
            'account' => $account['username'],
            'name' => $app['name'],
            'package' => $package,
        ]);
    }

    public function uninstallPackage(array $account, array $app, string $package): void
    {
        if (($app['runtime'] ?? '') !== 'python') {
            throw new \RuntimeException('Packages are only available for Python apps.');
        }
        $package = self::normalizePackage($package);
        app('provisioning')->execute('python.packages.uninstall', [
            'account' => $account['username'],
            'name' => $app['name'],
            'package' => $package,
        ]);
    }

    public function stop(array $account, array $app): bool
    {
        $r = app('provisioning')->execute('app.stop', [
            'account' => $account['username'],
            'name' => $app['name'],
        ]);
        return $r['status'] === 'success';
    }

    public function isRunning(array $account, array $app): bool
    {
        try {
            $r = app('provisioning')->execute('app.status', [
                'account' => $account['username'],
                'name' => $app['name'],
            ]);
            return trim($r['output'] ?? '') === 'active';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function logs(array $account, array $app): string
    {
        try {
            $r = app('provisioning')->execute('app.logs', [
                'account' => $account['username'],
                'name' => $app['name'],
            ]);
            return $r['output'] ?? '';
        } catch (\Throwable $e) {
            // Never render an empty log pane as "no output": the Manage → Logs
            // panel must say the read failed.
            app('logger')->warning('app.logs failed for ' . $account['username'] . '/' . $app['name'] . ': ' . $e->getMessage());
            return '(log unavailable: ' . $e->getMessage() . ')';
        }
    }

    public function deploy(array $account, array $app, bool $asAdmin = false): bool
    {
        app(\Modules\Packages\Services\FeatureService::class)->requireForAccount($account, 'applications', $asAdmin);
        if (empty($app['git_url'])) {
            return false;
        }
        $r = app('provisioning')->execute('app.deploy', [
            'account' => $account['username'],
            'name' => $app['name'],
            'work_dir' => $app['work_dir'],
            'git_url' => $app['git_url'],
            'git_branch' => $app['git_branch'] ?? 'main',
        ]);
        return $r['status'] === 'success';
    }

}
