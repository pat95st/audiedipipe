<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Query;

use AudiEdiPipe\Cqrs\Query;

final class ListPipelines implements Query
{
    public function __construct(
        public readonly bool $onlyEnabled = false,
    ) {
    }
}
