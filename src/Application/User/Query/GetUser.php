<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User\Query;

use AudiEdiPipe\Cqrs\Query;

final class GetUser implements Query
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}
