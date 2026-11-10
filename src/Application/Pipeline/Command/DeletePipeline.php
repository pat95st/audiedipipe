<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Command;

use AudiEdiPipe\Cqrs\Command;

final class DeletePipeline implements Command
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}
