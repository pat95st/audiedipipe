<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source\Command;

use AudiEdiPipe\Cqrs\Command;

final class SaveSource implements Command
{
    /**
     * @param array<mixed> $config
     * @param array<mixed> $filter
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly array $config,
        public readonly string $path,
        public readonly array $filter,
        public readonly int $pollInterval,
        public readonly string $afterImport,
        public readonly bool $enabled,
    ) {
    }
}
