<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

/**
 * Session abstraction so the HTTP kernel can be tested without PHP sessions.
 */
interface Session
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function regenerate(): void;

    public function destroy(): void;

    /**
     * Store a one-time flash message.
     */
    public function flash(string $type, string $message): void;

    /**
     * @return list<array{type: string, message: string}>
     */
    public function takeFlashes(): array;
}
