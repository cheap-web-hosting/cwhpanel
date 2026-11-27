<?php

declare(strict_types=1);

namespace App\Foundation\Database;

class Migrator
{
    private Connection $db;

    private string $table = 'migrations';

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function ensureTable(): void
    {
        $this->db->statement(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `migration` VARCHAR(255) NOT NULL,
                `module` VARCHAR(64) NULL,
                `batch` INT UNSIGNED NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `migrations_migration_unique` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function migrated(): array
    {
        $rows = $this->db->select("SELECT `migration` FROM `{$this->table}`");
        return array_column($rows, 'migration');
    }

    /**
     * Run migrations from a list of [module, class] pairs.
     *
     * @param array<int, array{0:string,1:string}> $migrations  [module, className]
     */
    public function run(array $migrations, bool $pretend = false): array
    {
        $this->ensureTable();
        $done = $this->migrated();
        $batch = (int) $this->db->scalar("SELECT COALESCE(MAX(`batch`), 0) FROM `{$this->table}`") + 1;

        $ran = [];

        foreach ($migrations as [$module, $className]) {
            if (in_array($className, $done, true)) {
                continue;
            }

            if ($pretend) {
                $ran[] = $className;
                continue;
            }

            $migration = $this->instantiate($className);
            $migration->setConnection($this->db);
            $migration->up();

            $this->db->insert($this->table, [
                'migration' => $className,
                'module' => $module,
                'batch' => $batch,
            ]);

            $ran[] = $className;
        }

        return $ran;
    }

    public function rollback(string $module, int $steps = 1): array
    {
        $this->ensureTable();

        $rows = $this->db->select(
            "SELECT * FROM `{$this->table}` WHERE `module` = ? ORDER BY `id` DESC LIMIT " . (int) $steps,
            [$module]
        );

        $rolled = [];

        foreach ($rows as $row) {
            $className = $row['migration'];
            $migration = $this->instantiate($className);
            $migration->setConnection($this->db);
            $migration->down();

            $this->db->delete($this->table, '`id` = ?', [$row['id']]);
            $rolled[] = $row['migration'];
        }

        return $rolled;
    }

    private function instantiate(string $className): Migration
    {
        if (!class_exists($className)) {
            throw new \RuntimeException("Migration class [{$className}] not found.");
        }

        $migration = new $className();

        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migration [{$className}] must extend " . Migration::class . '.');
        }

        return $migration;
    }
}
