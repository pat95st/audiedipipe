<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source\Command;

use AudiEdiPipe\Cqrs\Command;

/**
 * Complete the OAuth flow of a cloud storage location with the authorization code.
 */
final class ConnectSourceAccount implements Command
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
    ) {
    }
}
