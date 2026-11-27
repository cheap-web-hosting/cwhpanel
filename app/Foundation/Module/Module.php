<?php

declare(strict_types=1);

namespace App\Foundation\Module;

use App\Foundation\Container;
use App\Foundation\Router;

abstract class Module
{
    abstract public function id(): string;

    abstract public function name(): string;

    abstract public function version(): string;

    public function admin(): bool
    {
        return true;
    }

    public function customer(): bool
    {
        return false;
    }

    public function requires(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];
    }

    public function routes(Router $router): void
    {
    }

    public function navigation(): array
    {
        return [];
    }

    public function migrations(): array
    {
        return [];
    }

    public function bindings(Container $container): void
    {
    }

    /**
     * Background job handlers owned by this module: type => callable(array $payload): mixed.
     *
     * Handlers live in the owning module's Module.php so deleting/disabling a
     * module removes its job types with it — no shared registry keeps a class
     * reference to a module that may not exist.
     *
     * @return array<string, callable>
     */
    public function jobs(): array
    {
        return [];
    }

    public function boot(): void
    {
    }

    /**
     * True when every named table exists in the current database. Seeding
     * boot() implementations must consult this first: boot runs on every
     * bootstrap INCLUDING pre-migration states (clean installs), where the
     * tables may not exist yet. Seeding then simply happens on the first
     * post-migrate boot instead of fataling the install.
     */
    protected function tablesExist(array $tables): bool
    {
        try {
            $db = app('db');
            foreach ($tables as $table) {
                $row = $db->selectOne(
                    'SELECT COUNT(*) AS `n` FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE() AND `table_name` = ?',
                    [$table]
                );
                if ((int) ($row['n'] ?? 0) < 1) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }
}
