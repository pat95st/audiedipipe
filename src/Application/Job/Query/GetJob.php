<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Job\Query;

use AudiEdiPipe\Cqrs\Query;

final class GetJob implements Query
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}
