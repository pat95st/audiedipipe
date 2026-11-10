<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Database;

use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Persistence\Migrator;
use AudiEdiPipe\Tests\Support\TestCase;

final class MigratorTest extends TestCase
{
    public function test_migrate_creates_core_tables(): void
    {
        $db = $this->freshDb();
        $applied = (new Migrator($db, APP_ROOT . '/migrations'))->migrate();

        self::assertNotSame([], $applied, 'at least one migration should run');

        $tables = array_map(
            static fn (array $r): string => (string) $r['name'],
            $db->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table'"),
        );
        foreach (['users', 'pipelines', 'jobs', 'sources', 'settings', 'passkeys', 'auth_codes'] as $table) {
            self::assertContains($table, $tables, "missing table: {$table}");
        }
    }

    public function test_migrations_are_idempotent(): void
    {
        $db = $this->freshDb();
        $migrator = new Migrator($db, APP_ROOT . '/migrations');

        $first = $migrator->migrate();
        self::assertNotSame([], $first);

        $second = $migrator->migrate();
        self::assertSame([], $second, 'running migrations twice should apply nothing new');
        self::assertSame([], $migrator->pending());
    }

    private function freshDb(): Database
    {
        $dir = $this->tempDir('aepmig');

        return Database::fromConfig(['driver' => 'sqlite', 'path' => $dir . '/m.sqlite']);
    }
}
