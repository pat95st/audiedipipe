<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Template\Command;

use AudiEdiPipe\Cqrs\Command;

final class SaveTemplate implements Command
{
    /**
     * @param array<mixed> $fields
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly array $fields,
    ) {
    }
}
