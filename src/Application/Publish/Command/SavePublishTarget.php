<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Publish\Command;

use AudiEdiPipe\Cqrs\Command;

final class SavePublishTarget implements Command
{
    /**
     * @param array<mixed> $config
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly array $config,
        public readonly bool $enabled,
    ) {
    }
}
