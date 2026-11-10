<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Publish\Command;

use AudiEdiPipe\Cqrs\Command;

final class DeletePublishTarget implements Command
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}
