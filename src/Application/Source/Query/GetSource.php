<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source\Query;

use AudiEdiPipe\Cqrs\Query;

final class GetSource implements Query
{
    public function __construct(
        public readonly int $id,
        public readonly bool $withSecrets = false,
    ) {
    }
}
