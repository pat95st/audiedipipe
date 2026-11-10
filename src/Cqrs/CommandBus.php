<?php

declare(strict_types=1);

namespace AudiEdiPipe\Cqrs;

use AudiEdiPipe\Infrastructure\Persistence\Database;
use RuntimeException;

/**
 * Dispatches commands to exactly one handler. Every command runs inside a
 * database transaction, so a failing handler never leaves partial writes.
 */
final class CommandBus
{
    /** @var array<class-string<Command>, callable(Command): mixed> */
    private array $handlers = [];

    /** @var list<callable(Command, mixed): void> */
    private array $listeners = [];

    public function __construct(private readonly ?Database $database = null)
    {
    }

    /**
     * @param class-string<Command>     $commandClass
     * @param callable(Command): mixed  $handler
     */
    public function register(string $commandClass, callable $handler): void
    {
        $this->handlers[$commandClass] = $handler;
    }

    /**
     * Listener called after a command was handled successfully (e.g. audit log).
     *
     * @param callable(Command, mixed): void $listener
     */
    public function listen(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function dispatch(Command $command): mixed
    {
        $handler = $this->handlers[$command::class] ?? null;
        if ($handler === null) {
            throw new RuntimeException(sprintf('No handler registered for command %s.', $command::class));
        }
        $result = $this->database !== null
            ? $this->database->transaction(static fn (): mixed => $handler($command))
            : $handler($command);
        foreach ($this->listeners as $listener) {
            $listener($command, $result);
        }

        return $result;
    }

    public function handles(string $commandClass): bool
    {
        return isset($this->handlers[$commandClass]);
    }
}
