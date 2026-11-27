<?php

declare(strict_types=1);

namespace App\Foundation\Database;

abstract class Migration
{
    protected Connection $db;

    public function setConnection(Connection $db): void
    {
        $this->db = $db;
    }

    abstract public function up(): void;

    public function down(): void
    {
    }

    protected function createTable(string $table, callable $builder): void
    {
        $schema = new Schema($this->db, $table);
        $builder($schema);
        $schema->create();
    }

    protected function dropTable(string $table): void
    {
        $this->db->statement("DROP TABLE IF EXISTS `{$table}`");
    }
}
