<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Database;

use AudiEdiPipe\Infrastructure\Persistence\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseTest extends TestCase
{
    public function test_insert_returns_id_and_fetch_roundtrips(): void
    {
        $db = $this->memory();
        $id = $db->insert('t', ['name' => 'alpha', 'n' => 5]);
        self::assertGreaterThan(0, $id);

        $row = $db->fetchOne('SELECT name, n FROM t WHERE id = :id', [':id' => $id]);
        self::assertSame('alpha', $row['name']);
        self::assertSame(5, (int) $row['n']);
    }

    public function test_fetch_value_and_update(): void
    {
        $db = $this->memory();
        $id = $db->insert('t', ['name' => 'beta', 'n' => 1]);
        $db->update('t', ['n' => 99], ['id' => $id]);

        self::assertSame(99, (int) $db->fetchValue('SELECT n FROM t WHERE id = :id', [':id' => $id]));
    }

    public function test_transaction_commits_on_success(): void
    {
        $db = $this->memory();
        $db->transaction(static function () use ($db): void {
            $db->insert('t', ['name' => 'x', 'n' => 1]);
            $db->insert('t', ['name' => 'y', 'n' => 2]);
        });
        self::assertSame(2, (int) $db->fetchValue('SELECT COUNT(*) FROM t'));
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        $db = $this->memory();

        try {
            $db->transaction(static function () use ($db): void {
                $db->insert('t', ['name' => 'x', 'n' => 1]);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM t'));
    }

    public function test_nested_transaction_rolls_back_as_one_unit(): void
    {
        $db = $this->memory();

        try {
            $db->transaction(static function () use ($db): void {
                $db->insert('t', ['name' => 'outer', 'n' => 1]);
                $db->transaction(static function () use ($db): void {
                    $db->insert('t', ['name' => 'inner', 'n' => 2]);

                    throw new RuntimeException('boom');
                });
            });
        } catch (RuntimeException) {
            // expected
        }
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM t'));
    }

    private function memory(): Database
    {
        $db = Database::fromConfig(['driver' => 'sqlite', 'path' => ':memory:']);
        $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, n INTEGER)');

        return $db;
    }
}
