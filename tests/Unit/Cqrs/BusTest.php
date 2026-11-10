<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Cqrs;

use AudiEdiPipe\Cqrs\Command;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Cqrs\Query;
use AudiEdiPipe\Cqrs\QueryBus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DummyCommand implements Command
{
    public function __construct(public readonly int $value)
    {
    }
}

final class DummyQuery implements Query
{
    public function __construct(public readonly string $needle)
    {
    }
}

final class BusTest extends TestCase
{
    public function test_command_bus_dispatches_to_registered_handler(): void
    {
        $bus = new CommandBus(); // no database -> runs handler directly
        $bus->register(DummyCommand::class, static fn (DummyCommand $c): int => $c->value * 2);

        self::assertSame(42, $bus->dispatch(new DummyCommand(21)));
    }

    public function test_command_bus_notifies_listeners_after_handling(): void
    {
        $bus = new CommandBus();
        $seen = [];
        $bus->register(DummyCommand::class, static fn (DummyCommand $c): int => $c->value);
        $bus->listen(static function (Command $c, mixed $result) use (&$seen): void {
            $seen[] = [$c::class, $result];
        });

        $bus->dispatch(new DummyCommand(7));
        self::assertSame([[DummyCommand::class, 7]], $seen);
    }

    public function test_command_bus_reports_handled_commands(): void
    {
        $bus = new CommandBus();
        self::assertFalse($bus->handles(DummyCommand::class));
        $bus->register(DummyCommand::class, static fn (DummyCommand $c): int => $c->value);
        self::assertTrue($bus->handles(DummyCommand::class));
    }

    public function test_command_bus_throws_for_unknown_command(): void
    {
        $this->expectException(RuntimeException::class);
        (new CommandBus())->dispatch(new DummyCommand(1));
    }

    public function test_query_bus_dispatches_to_registered_handler(): void
    {
        $bus = new QueryBus();
        $bus->register(DummyQuery::class, static fn (DummyQuery $q): string => strtoupper($q->needle));

        self::assertSame('HELLO', $bus->ask(new DummyQuery('hello')));
    }

    public function test_query_bus_throws_for_unknown_query(): void
    {
        $this->expectException(RuntimeException::class);
        (new QueryBus())->ask(new DummyQuery('x'));
    }
}
