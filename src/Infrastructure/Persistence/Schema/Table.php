<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Persistence\Schema;

/**
 * Database agnostic table definition used by migrations.
 */
final class Table
{
    /** @var list<Column> */
    public array $columns = [];

    /** @var list<array{columns: list<string>, unique: bool, name: string}> */
    public array $indexes = [];

    /** @var list<array{column: string, table: string, onDelete: string}> */
    public array $foreignKeys = [];

    public function __construct(public readonly string $name)
    {
    }

    public function id(): self
    {
        $this->columns[] = new Column('id', 'id');

        return $this;
    }

    public function string(string $name, int $length = 255, bool $nullable = false, ?string $default = null): self
    {
        $this->columns[] = new Column($name, 'string', $length, $nullable, $default);

        return $this;
    }

    public function text(string $name, bool $nullable = true): self
    {
        $this->columns[] = new Column($name, 'text', null, $nullable);

        return $this;
    }

    public function integer(string $name, bool $nullable = false, ?int $default = null): self
    {
        $this->columns[] = new Column($name, 'integer', null, $nullable, $default === null ? null : (string) $default);

        return $this;
    }

    public function bigInteger(string $name, bool $nullable = false, ?int $default = null): self
    {
        $this->columns[] = new Column($name, 'bigint', null, $nullable, $default === null ? null : (string) $default);

        return $this;
    }

    public function boolean(string $name, bool $default = false): self
    {
        $this->columns[] = new Column($name, 'boolean', null, false, $default ? '1' : '0');

        return $this;
    }

    public function float(string $name, bool $nullable = true): self
    {
        $this->columns[] = new Column($name, 'float', null, $nullable);

        return $this;
    }

    public function datetime(string $name, bool $nullable = true): self
    {
        $this->columns[] = new Column($name, 'datetime', null, $nullable);

        return $this;
    }

    public function timestamps(): self
    {
        $this->datetime('created_at', false);
        $this->datetime('updated_at', false);

        return $this;
    }

    /**
     * @param list<string> $columns
     */
    public function index(array $columns, bool $unique = false): self
    {
        $this->indexes[] = [
            'columns' => $columns,
            'unique' => $unique,
            'name' => ($unique ? 'uq_' : 'ix_') . $this->name . '_' . implode('_', $columns),
        ];

        return $this;
    }

    /**
     * @param list<string> $columns
     */
    public function unique(array $columns): self
    {
        return $this->index($columns, true);
    }

    /**
     * @param 'CASCADE'|'SET NULL'|'RESTRICT' $onDelete
     */
    public function foreign(string $column, string $table, string $onDelete = 'CASCADE'): self
    {
        $this->foreignKeys[] = ['column' => $column, 'table' => $table, 'onDelete' => $onDelete];

        return $this;
    }
}
