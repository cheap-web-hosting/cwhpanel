<?php

declare(strict_types=1);

namespace App\Foundation\Module;

use App\Foundation\Container;
use App\Foundation\Database\Connection;
use App\Foundation\Navigation\NavigationRegistry;
use App\Foundation\Router;

class ModuleManager
{
    private string $modulesDir;

    private Container $container;

    private Router $router;

    private NavigationRegistry $navRegistry;

    /**
     * @var array<string, array{module: Module, manifest: Manifest, enabled: bool}>
     */
    private array $modules = [];

    /**
     * @var array<int, array{0:string, 1:string}> [module, className]
     */
    private array $migrationQueue = [];

    /**
     * @var array<string, array{name:string, module:string}>
     */
    private array $permissionList = [];

    /**
     * Aggregated background job handlers contributed by enabled modules.
     *
     * @var array<string,callable>
     */
    private array $jobs = [];

    /**
     * Modules that did not load, with the reason. Two sources:
     *  - `disabled by configuration` (modules.is_enabled = 0)
     *  - cascade: a dependency is missing/circular
     *  - load failures (bad class/manifest)
     *
     * @var array<string,string>
     */
    private array $skipped = [];

    /**
     * Modules that loaded but failed a lifecycle stage (bindings, routes,
     * boot, …). Their already-registered pieces stay; later stages skip them.
     *
     * @var array<string,string>
     */
    private array $degraded = [];

    public function __construct(
        string $modulesDir,
        Container $container,
        Router $router,
        NavigationRegistry $navRegistry
    ) {
        $this->modulesDir = $modulesDir;
        $this->container = $container;
        $this->router = $router;
        $this->navRegistry = $navRegistry;
    }

    public function discover(): array
    {
        if (!is_dir($this->modulesDir)) {
            return [];
        }

        $dirs = glob($this->modulesDir . '/*', GLOB_ONLYDIR);

        $discovered = [];

        foreach ($dirs as $dir) {
            $slug = basename($dir);
            $manifestFile = $dir . '/manifest.json';
            $moduleFile = $dir . '/Module.php';

            if (!is_file($moduleFile)) {
                continue;
            }

            try {
                $manifest = Manifest::fromFile($manifestFile);
                $className = 'Modules\\' . $slug . '\\Module';

                if (!class_exists($className)) {
                    require_once $moduleFile;

                    if (!class_exists($className)) {
                        $this->skipped[$slug] = 'Module.php did not declare ' . $className;
                        continue;
                    }
                }

                $id = $manifest->id() ?: $slug;

                $discovered[$id] = [
                    'slug' => $slug,
                    'class' => $className,
                    'manifest' => $manifest,
                    'dir' => $dir,
                ];
            } catch (\Throwable $e) {
                $this->skipped[$slug] = 'discovery failed: ' . $e->getMessage();
                $this->logFailure($slug, 'discover', $e);
            }
        }

        return $discovered;
    }

    public function register(): void
    {
        $discovered = $this->discover();

        $enabledModules = $this->loadEnabledFromDb();

        foreach ($discovered as $id => $info) {
            $enabled = $enabledModules[$id] ?? true;

            if (!$enabled) {
                $this->skipped[$id] = 'disabled by configuration';
                continue;
            }

            try {
                $className = $info['class'];
                $module = new $className();

                if (!($module instanceof Module)) {
                    $this->skipped[$id] = 'module class does not extend the base Module';
                    continue;
                }

                $id = $module->id() ?: $id;

                $this->modules[$id] = [
                    'module' => $module,
                    'manifest' => $info['manifest'],
                    'enabled' => true,
                ];
            } catch (\Throwable $e) {
                $this->skipped[$id] = 'load failed: ' . $e->getMessage();
                $this->logFailure($id, 'load', $e);
            }
        }

        $ordered = $this->resolveDependencies();
        $this->modules = $ordered;

        foreach ($this->modules as $slug => $info) {
            if (isset($this->degraded[$slug])) {
                continue;
            }

            try {
                foreach ($info['module']->permissions() as $permName) {
                    $this->permissionList[$permName] = [
                        'name' => $permName,
                        'module' => $slug,
                    ];
                }
            } catch (\Throwable $e) {
                $this->degrade($slug, 'permissions', $e);
            }
        }

        foreach ($this->modules as $slug => $info) {
            if (isset($this->degraded[$slug])) {
                continue;
            }

            try {
                $info['module']->bindings($this->container);
            } catch (\Throwable $e) {
                $this->degrade($slug, 'bindings', $e);
            }
        }

        foreach ($this->modules as $slug => $info) {
            if (isset($this->degraded[$slug])) {
                continue;
            }

            try {
                $this->navRegistry->addModuleNav($slug, $info['module']->navigation());
            } catch (\Throwable $e) {
                $this->degrade($slug, 'navigation', $e);
            }
        }

        foreach ($this->modules as $slug => $info) {
            if (isset($this->degraded[$slug])) {
                continue;
            }

            try {
                foreach ($info['module']->jobs() as $type => $handler) {
                    if (!is_callable($handler)) {
                        $this->logFailure($slug, 'jobs', new \RuntimeException("job '{$type}' handler is not callable"));
                        continue;
                    }
                    if (isset($this->jobs[$type])) {
                        $this->logFailure($slug, 'jobs', new \RuntimeException("job '{$type}' is already registered by another module"));
                        continue;
                    }
                    $this->jobs[$type] = $handler;
                }
            } catch (\Throwable $e) {
                $this->degrade($slug, 'jobs', $e);
            }
        }

        foreach ($this->modules as $slug => $info) {
            if (isset($this->degraded[$slug])) {
                continue;
            }

            try {
                $info['module']->routes($this->router);
            } catch (\Throwable $e) {
                $this->degrade($slug, 'routes', $e);
            }
        }

        foreach ($this->modules as $slug => $info) {
            if (isset($this->degraded[$slug])) {
                continue;
            }

            try {
                foreach ($info['module']->migrations() as $class) {
                    $this->migrationQueue[] = [$slug, $class];
                }
            } catch (\Throwable $e) {
                $this->degrade($slug, 'migrations', $e);
            }
        }

        foreach ($this->modules as $slug => $info) {
            if (isset($this->degraded[$slug])) {
                continue;
            }

            try {
                $info['module']->boot();
            } catch (\Throwable $e) {
                $this->degrade($slug, 'boot', $e);
            }
        }
    }

    public function isEnabled(string $moduleId): bool
    {
        if (isset($this->skipped[$moduleId]) || isset($this->degraded[$moduleId])) {
            return false;
        }

        return isset($this->modules[$moduleId]) && $this->modules[$moduleId]['enabled'];
    }

    /**
     * Modules that did not load, mapped to the reason. Used by the admin
     * Modules page and by the module-matrix test.
     *
     * @return array<string,string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * Modules that loaded but failed a lifecycle stage, mapped to the reason.
     *
     * @return array<string,string>
     */
    public function degraded(): array
    {
        return $this->degraded;
    }

    /** @return array<string,callable> job type => handler from enabled modules */
    public function jobs(): array
    {
        return $this->jobs;
    }

    public function jobHandler(string $type): ?callable
    {
        return $this->jobs[$type] ?? null;
    }

    /**
     * @return array<int, array{0:string, 1:string}>
     */
    public function migrations(): array
    {
        return $this->migrationQueue;
    }

    /**
     * @return array<string, array{name:string, module:string}>
     */
    public function permissions(): array
    {
        return $this->permissionList;
    }

    /**
     * @return array<string, array{module: Module, manifest: Manifest, enabled: bool}>
     */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $moduleId): ?Module
    {
        return $this->modules[$moduleId]['module'] ?? null;
    }

    private function resolveDependencies(): array
    {
        $resolved = [];
        $unresolved = [];

        $resolve = function (string $slug) use (&$resolve, &$resolved, &$unresolved): bool {
            if (isset($resolved[$slug])) {
                return true;
            }

            if (isset($unresolved[$slug])) {
                $chain = implode(' -> ', array_keys($unresolved)) . ' -> ' . $slug;
                $this->skipped[$slug] = 'circular dependency: ' . $chain;
                $this->logFailure($slug, 'dependency', new \RuntimeException('circular dependency: ' . $chain));
                return false;
            }

            $info = $this->modules[$slug] ?? null;

            if ($info === null) {
                return false;
            }

            $unresolved[$slug] = true;

            try {
                $deps = array_unique(array_merge(
                    $info['module']->requires(),
                    $info['manifest']->requires()
                ));
            } catch (\Throwable $e) {
                unset($unresolved[$slug]);
                $this->skipped[$slug] = 'dependency declaration failed: ' . $e->getMessage();
                $this->logFailure($slug, 'dependency', $e);
                return false;
            }

            foreach ($deps as $dep) {
                if (!isset($this->modules[$dep]) || !$resolve($dep)) {
                    unset($unresolved[$slug]);
                    $this->skipped[$slug] = "requires unavailable module '{$dep}'";
                    $this->logFailure(
                        $slug,
                        'dependency',
                        new \RuntimeException("required module '{$dep}' is disabled, missing or failed to load")
                    );
                    return false;
                }
            }

            unset($unresolved[$slug]);
            $resolved[$slug] = $info;

            return true;
        };

        foreach (array_keys($this->modules) as $slug) {
            $resolve($slug);
        }

        return $resolved;
    }

    /** Mark a loaded module as partially broken and stop its later stages. */
    private function degrade(string $slug, string $stage, \Throwable $e): void
    {
        $this->degraded[$slug] = "{$stage}: " . $e->getMessage();
        $this->logFailure($slug, $stage, $e);
    }

    private function logFailure(string $slug, string $stage, \Throwable $e): void
    {
        try {
            app('logger')->error(
                "Module '{$slug}' {$stage} failed; continuing without it: " . $e->getMessage()
            );
        } catch (\Throwable) {
            // logger unavailable (very early boot) — never mask the original
        }
    }

    private function loadEnabledFromDb(): array
    {
        try {
            $db = $this->container->get(Connection::class);

            $result = $db->statement(
                "SELECT * FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'modules'"
            );

            if ($result->rowCount() === 0) {
                return [];
            }

            $rows = $db->select('SELECT `id`, `is_enabled` FROM `modules`');
            $map = [];

            foreach ($rows as $row) {
                $map[$row['id']] = (bool) $row['is_enabled'];
            }

            return $map;
        } catch (\Throwable $e) {
            // A real DB failure must not silently nullify an admin's module
            // disable (empty map = every module enabled). Behavior is kept
            // (a fresh install's missing modules table is expected), but the
            // failure is logged loudly so it cannot masquerade as "no
            // disables configured".
            try {
                app('logger')->error('Failed to read module enablement state; defaulting to all modules enabled: ' . $e->getMessage());
            } catch (\Throwable $ignored) {
            }
            return [];
        }
    }
}