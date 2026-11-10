<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Command;

use AudiEdiPipe\Cqrs\Command;

final class UpdateUser implements Command
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $role,
        public readonly bool $active,
        public readonly ?string $displayName = null,
        public readonly ?string $password = null,
    ) {
    }
}
