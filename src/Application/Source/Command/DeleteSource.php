<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source\Command;

use AudiEdiPipe\Cqrs\Command;

final class DeleteSource implements Command
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}
