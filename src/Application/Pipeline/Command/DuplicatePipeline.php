<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Command;

use AudiEdiPipe\Cqrs\Command;

final class DuplicatePipeline implements Command
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId = null,
    ) {
    }
}
