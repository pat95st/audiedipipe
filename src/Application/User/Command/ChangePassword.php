<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Command;

use AudiEdiPipe\Cqrs\Command;

final class ChangePassword implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $currentPassword,
        public readonly string $newPassword,
    ) {
    }
}
