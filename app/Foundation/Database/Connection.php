<?php

declare(strict_types=1);

namespace App\Foundation\Database;

use PDO;
use PDOStatement;

class Connection
{
    private PDO $pdo;

    private string $dsn;

    private string $username;

    private string $password;

    public function __construct(string $host, int $port, string $database, string $username, string $password = '', string $charset = 'utf8mb4')
    {
        $this->dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        $this->username = $username;
        $this->password = $password;
        $this->pdo = $this->connect();
    }

    private function connect(): PDO
    {
        return new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Re-open the connection.
     *
     * Long-lived processes (the job worker, the mail-policy loop) keep one PDO
     * alive for days; a MariaDB restart — the remote-MySQL toggle restarts it
     * on purpose — killed it and the worker then looped forever on
     * "MySQL server has gone away", never processing another job.
     */
    public function reconnect(): void
    {
        $this->pdo = $this->connect();
    }

    /** Is this "connection died underneath us" rather than a query error? */
    private function isLostConnection(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($code, [2006, 2007, 2013, 2014, 2055], true)) {
            return true;
        }
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'server has gone away')
            || str_contains($msg, 'lost connection')
            || str_contains($msg, 'broken pipe')
            || str_contains($msg, 'error while sending');
    }

    public function statement(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (\PDOException $e) {
            if (!$this->isLostConnection($e)) {
                throw $e;
            }
            // Reconnect and retry once. Prepared statements are bound to the
            // old handle, so the SQL must be re-prepared.
            $this->reconnect();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        }
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->statement($sql, $params)->fetchAll();
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->statement($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = [])
    {
        return $this->statement($sql, $params)->fetchColumn();
    }

    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_map(fn ($c) => "`{$c}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $this->statement("INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})", array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));

        $stmt = $this->statement(
            "UPDATE `{$table}` SET {$sets} WHERE {$where}",
            array_merge(array_values($data), $params)
        );

        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $stmt = $this->statement("DELETE FROM `{$table}` WHERE {$where}", $params);

        return $stmt->rowCount();
    }

    public function transaction(callable $callback)
    {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
