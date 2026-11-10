<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Persistence;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper supporting MariaDB/MySQL, PostgreSQL and SQLite.
 */
final class Database
{
    private int $transactionDepth = 0;

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $driver = (string) ($config['driver'] ?? 'mysql');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        switch ($driver) {
            case 'sqlite':
                $dsn = 'sqlite:' . (string) ($config['path'] ?? ':memory:');
                $pdo = new PDO($dsn, null, null, $options);

                break;

            case 'pgsql':
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    (string) ($config['host'] ?? '127.0.0.1'),
                    (int) ($config['port'] ?? 5432),
                    (string) ($config['name'] ?? ''),
                );
                $pdo = new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), $options);
                $pdo->exec("SET NAMES 'UTF8'");

                break;

            default:
                $socket = (string) ($config['socket'] ?? '');
                $dsn = $socket !== ''
                    ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, (string) ($config['name'] ?? ''))
                    : sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        (string) ($config['host'] ?? '127.0.0.1'),
                        (int) ($config['port'] ?? 3306),
                        (string) ($config['name'] ?? ''),
                    );
                $pdo = new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), $options);
                $pdo->exec("SET time_zone = '+00:00'");
        }

        return new self($pdo);
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', $placeholders),
        );
        $params = [];
        foreach ($data as $column => $value) {
            $params[':' . $column] = $this->normalize($value);
        }
        if ($this->driver() === 'pgsql') {
            return (int) $this->fetchValue($sql . ' RETURNING id', $params);
        }
        $this->run($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . ' = :s_' . $column;
            $params[':s_' . $column] = $this->normalize($value);
        }
        [$whereSql, $whereParams] = $this->buildWhere($where);

        return $this->execute(
            sprintf('UPDATE %s SET %s WHERE %s', $this->quoteIdentifier($table), implode(', ', $set), $whereSql),
            $params + $whereParams,
        );
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        [$whereSql, $params] = $this->buildWhere($where);

        return $this->execute(sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), $whereSql), $params);
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        }
        ++$this->transactionDepth;

        try {
            $result = $callback();
            --$this->transactionDepth;
            if ($this->transactionDepth === 0) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            --$this->transactionDepth;
            if ($this->transactionDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function quoteIdentifier(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';

        return $this->driver() === 'mysql' ? '`' . $clean . '`' : '"' . $clean . '"';
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $value = $this->normalize($value);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $where): array
    {
        if ($where === []) {
            return ['1 = 1', []];
        }
        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $parts[] = $this->quoteIdentifier($column) . ' IS NULL';

                continue;
            }
            $parts[] = $this->quoteIdentifier($column) . ' = :w_' . $column;
            $params[':w_' . $column] = $this->normalize($value);
        }

        return [implode(' AND ', $parts), $params];
    }
}
