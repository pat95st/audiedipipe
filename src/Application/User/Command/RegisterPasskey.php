<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Command;

use AudiEdiPipe\Cqrs\Command;

final class RegisterPasskey implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly string $credentialId,
        public readonly string $publicKeyPem,
        public readonly int $signCount,
    ) {
    }
}
