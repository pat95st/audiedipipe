<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Command;

use AudiEdiPipe\Cqrs\Command;

final class EnableTotp implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $secret,
        public readonly string $code,
    ) {
    }
}
