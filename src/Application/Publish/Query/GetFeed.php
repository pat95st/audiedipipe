<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Publish\Query;

use AudiEdiPipe\Cqrs\Query;

final class GetFeed implements Query
{
    public function __construct(
        public readonly string $slug,
    ) {
    }
}
