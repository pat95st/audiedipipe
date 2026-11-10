<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Template\Command;

use AudiEdiPipe\Cqrs\Command;

final class DeleteTemplate implements Command
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}
