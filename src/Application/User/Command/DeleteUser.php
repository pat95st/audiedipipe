<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Command;

use AudiEdiPipe\Cqrs\Command;

final class DeleteUser implements Command
{
    public function __construct(
        public readonly int $id,
        public readonly int $actorId,
    ) {
    }
}
