<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Command;

use AudiEdiPipe\Cqrs\Command;

final class DeletePasskey implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly int $passkeyId,
    ) {
    }
}
