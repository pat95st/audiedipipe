<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Persistence;

use AudiEdiPipe\Infrastructure\Persistence\Schema\SchemaBuilder;
use AudiEdiPipe\Infrastructure\Persistence\Schema\Table;
use RuntimeException;

/**
 * Runs PHP migration files from the migrations directory in order.
 *
 * A migration file returns a callable: function (Migrator $m): void
 */
final class Migrator
{
    private SchemaBuilder $schema;

    public function __construct(private readonly Database $db, private readonly string $directory)
    {
        $this->schema = new SchemaBuilder($db->driver());
    }

    /**
     * @return list<string> names of migrations that were applied
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $applied = array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->db->fetchAll('SELECT name FROM migrations'),
        );
        $files = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        sort($files);
        $ran = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $applied, true)) {
                continue;
            }
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException('Migration ' . $name . ' must return a callable.');
            }
            // MySQL auto-commits DDL, so transactions only give guarantees on PostgreSQL/SQLite.
            $migration($this);
            $this->db->insert('migrations', ['name' => $name, 'applied_at' => Database::now()]);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        $this->ensureMigrationsTable();
        $applied = array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->db->fetchAll('SELECT name FROM migrations'),
        );
        $files = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $applied, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * @param callable(Table): void $definition
     */
    public function create(string $tableName, callable $definition): void
    {
        $table = new Table($tableName);
        $definition($table);
        foreach ($this->schema->createTable($table) as $statement) {
            $this->db->execute($statement);
        }
    }

    public function drop(string $tableName): void
    {
        $this->db->execute($this->schema->dropTable($tableName));
    }

    public function db(): Database
    {
        return $this->db;
    }

    private function ensureMigrationsTable(): void
    {
        $exists = match ($this->db->driver()) {
            'sqlite' => $this->db->fetchValue("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'migrations'"),
            'pgsql' => $this->db->fetchValue("SELECT to_regclass('public.migrations')"),
            default => $this->db->fetchValue("SHOW TABLES LIKE 'migrations'"),
        };
        if ($exists !== null && $exists !== false) {
            return;
        }
        $this->create('migrations', static function (Table $t): void {
            $t->id()->string('name', 190)->datetime('applied_at', false)->unique(['name']);
        });
    }
}
