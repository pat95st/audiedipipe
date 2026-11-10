<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Command;

use AudiEdiPipe\Cqrs\Command;

final class CreateUser implements Command
{
    public function __construct(
        public readonly string $username,
        public readonly string $email,
        public readonly string $password,
        public readonly string $role,
        public readonly ?string $displayName = null,
        public readonly ?string $locale = null,
    ) {
    }
}
