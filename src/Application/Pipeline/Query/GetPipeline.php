<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Query;

use AudiEdiPipe\Cqrs\Query;

final class GetPipeline implements Query
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}
