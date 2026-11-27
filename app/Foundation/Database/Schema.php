<?php

declare(strict_types=1);

namespace App\Foundation\Database;

class Schema
{
    private Connection $db;

    private string $table;

    private array $columns = [];

    private array $indexes = [];

    public function __construct(Connection $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;
    }

    public function id(): self
    {
        $this->columns[] = '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        return $this;
    }

    public function bigIncrements(string $name): self
    {
        $this->columns[] = "`{$name}` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY";
        return $this;
    }

    public function string(string $name, int $length = 255): self
    {
        $this->columns[] = "`{$name}` VARCHAR({$length}) NOT NULL";
        return $this;
    }

    public function stringPrimary(string $name, int $length = 255): self
    {
        $this->columns[] = "`{$name}` VARCHAR({$length}) NOT NULL PRIMARY KEY";
        return $this;
    }

    public function nullableString(string $name, int $length = 255): self
    {
        $this->columns[] = "`{$name}` VARCHAR({$length}) NULL";
        return $this;
    }

    public function text(string $name): self
    {
        $this->columns[] = "`{$name}` TEXT NOT NULL";
        return $this;
    }

    public function nullableText(string $name): self
    {
        $this->columns[] = "`{$name}` TEXT NULL";
        return $this;
    }

    public function integer(string $name, bool $unsigned = false): self
    {
        $this->columns[] = "`{$name}` INT" . ($unsigned ? ' UNSIGNED' : '') . " NOT NULL";
        return $this;
    }

    public function nullableInteger(string $name, bool $unsigned = false): self
    {
        $this->columns[] = "`{$name}` INT" . ($unsigned ? ' UNSIGNED' : '') . " NULL";
        return $this;
    }

    public function unsignedInteger(string $name): self
    {
        return $this->integer($name, true);
    }

    public function bigInteger(string $name, bool $unsigned = false): self
    {
        $this->columns[] = "`{$name}` BIGINT" . ($unsigned ? ' UNSIGNED' : '') . " NOT NULL";
        return $this;
    }

    public function nullableBigInteger(string $name, bool $unsigned = false): self
    {
        $this->columns[] = "`{$name}` BIGINT" . ($unsigned ? ' UNSIGNED' : '') . " NULL";
        return $this;
    }

    public function unsignedBigInteger(string $name): self
    {
        return $this->bigInteger($name, true);
    }

    public function boolean(string $name): self
    {
        $this->columns[] = "`{$name}` TINYINT(1) NOT NULL DEFAULT 0";
        return $this;
    }

    public function timestamp(string $name, bool $nullable = false): self
    {
        $this->columns[] = "`{$name}` TIMESTAMP" . ($nullable ? ' NULL' : ' NOT NULL');
        return $this;
    }

    public function timestamps(): self
    {
        $this->columns[] = '`created_at` TIMESTAMP NULL DEFAULT NULL';
        $this->columns[] = '`updated_at` TIMESTAMP NULL DEFAULT NULL';
        return $this;
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): self
    {
        $this->columns[] = "`{$name}` DECIMAL({$precision},{$scale}) NOT NULL DEFAULT 0";
        return $this;
    }

    public function index(string $column, ?string $name = null): self
    {
        $name = $name ?? $this->table . '_' . $column . '_idx';
        $this->indexes[] = "KEY `{$name}` (`{$column}`)";
        return $this;
    }

    public function unique(string $column, ?string $name = null): self
    {
        $name = $name ?? $this->table . '_' . $column . '_unique';
        $this->indexes[] = "UNIQUE KEY `{$name}` (`{$column}`)";
        return $this;
    }

    public function create(): void
    {
        $parts = array_merge($this->columns, $this->indexes);
        $sql = "CREATE TABLE `{$this->table}` (" . implode(', ', $parts) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->statement($sql);
    }
}
