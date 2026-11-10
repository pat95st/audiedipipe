<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Command;

use AudiEdiPipe\Cqrs\Command;

final class UpdateProfile implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly ?string $displayName,
        public readonly ?string $locale,
        public readonly string $theme,
    ) {
    }
}
