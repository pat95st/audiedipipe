<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Persistence\Schema;

final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly bool $nullable = false,
        public readonly ?string $default = null,
    ) {
    }
}
