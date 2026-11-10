<?php

declare(strict_types=1);

namespace AudiEdiPipe\Kernel;

use RuntimeException;

/**
 * Minimal service container with lazy, shared factories.
 */
final class Container
{
    /** @var array<string, callable(Container): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param callable(Container): object $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, object $service): void
    {
        $this->instances[$id] = $service;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function get(string $id): object
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->factories[$id])) {
                throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
            }
            $this->instances[$id] = ($this->factories[$id])($this);
        }
        $service = $this->instances[$id];
        if (!$service instanceof $id) {
            throw new RuntimeException(sprintf('Service "%s" has an unexpected type.', $id));
        }

        return $service;
    }
}
