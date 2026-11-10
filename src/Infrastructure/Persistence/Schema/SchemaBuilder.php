<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Persistence\Schema;

use InvalidArgumentException;

/**
 * Generates dialect specific DDL for MariaDB/MySQL, PostgreSQL and SQLite.
 */
final class SchemaBuilder
{
    public function __construct(private readonly string $driver)
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new InvalidArgumentException('Unsupported database driver: ' . $driver);
        }
    }

    /**
     * @return list<string>
     */
    public function createTable(Table $table): array
    {
        $lines = [];
        foreach ($table->columns as $column) {
            $lines[] = '    ' . $this->columnSql($column);
        }
        foreach ($table->foreignKeys as $fk) {
            $lines[] = sprintf(
                '    FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
                $this->quote($fk['column']),
                $this->quote($fk['table']),
                $this->quote('id'),
                $fk['onDelete'],
            );
        }
        $sql = sprintf("CREATE TABLE %s (\n%s\n)", $this->quote($table->name), implode(",\n", $lines));
        if ($this->driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        $statements = [$sql];
        foreach ($table->indexes as $index) {
            $statements[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['unique'] ? 'UNIQUE ' : '',
                $this->quote($index['name']),
                $this->quote($table->name),
                implode(', ', array_map($this->quote(...), $index['columns'])),
            );
        }

        return $statements;
    }

    public function dropTable(string $name): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quote($name);
    }

    public function quote(string $identifier): string
    {
        return $this->driver === 'mysql' ? '`' . $identifier . '`' : '"' . $identifier . '"';
    }

    private function columnSql(Column $column): string
    {
        $name = $this->quote($column->name);
        if ($column->type === 'id') {
            return match ($this->driver) {
                'mysql' => $name . ' INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                'pgsql' => $name . ' SERIAL PRIMARY KEY',
                default => $name . ' INTEGER PRIMARY KEY AUTOINCREMENT',
            };
        }
        $type = match ($column->type) {
            'string' => sprintf('VARCHAR(%d)', $column->length ?? 255),
            'text' => match ($this->driver) {
                'mysql' => 'LONGTEXT',
                default => 'TEXT',
            },
            'integer' => match ($this->driver) {
                'mysql' => 'INT',
                default => 'INTEGER',
            },
            'bigint' => 'BIGINT',
            'boolean' => 'SMALLINT',
            'float' => match ($this->driver) {
                'pgsql' => 'DOUBLE PRECISION',
                'sqlite' => 'REAL',
                default => 'DOUBLE',
            },
            'datetime' => match ($this->driver) {
                'mysql' => 'DATETIME',
                'pgsql' => 'TIMESTAMP(0)',
                default => 'TEXT',
            },
            default => throw new InvalidArgumentException('Unknown column type ' . $column->type),
        };
        // Foreign key columns referencing unsigned MySQL ids must be unsigned too.
        if ($this->driver === 'mysql' && $column->type === 'integer' && str_ends_with($column->name, '_id')) {
            $type = 'INT UNSIGNED';
        }
        $sql = $name . ' ' . $type . ($column->nullable ? ' NULL' : ' NOT NULL');
        if ($column->default !== null) {
            $sql .= ' DEFAULT ' . (is_numeric($column->default) ? $column->default : "'" . str_replace("'", "''", $column->default) . "'");
        }

        return $sql;
    }
}
