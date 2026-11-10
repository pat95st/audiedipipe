<?php

declare(strict_types=1);

namespace AudiEdiPipe\Cqrs;

use RuntimeException;

/**
 * Dispatches queries to their read-model handlers.
 */
final class QueryBus
{
    /** @var array<class-string<Query>, callable(Query): mixed> */
    private array $handlers = [];

    /**
     * @param class-string<Query>    $queryClass
     * @param callable(Query): mixed $handler
     */
    public function register(string $queryClass, callable $handler): void
    {
        $this->handlers[$queryClass] = $handler;
    }

    public function ask(Query $query): mixed
    {
        $handler = $this->handlers[$query::class] ?? null;
        if ($handler === null) {
            throw new RuntimeException(sprintf('No handler registered for query %s.', $query::class));
        }

        return $handler($query);
    }
}
