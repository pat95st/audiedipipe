<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Template\Query;

use AudiEdiPipe\Cqrs\Query;

final class GetTemplate implements Query
{
    public function __construct(
        public readonly int $id,
    ) {
    }
}
