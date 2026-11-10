<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Publish\Query;

use AudiEdiPipe\Cqrs\Query;

final class GetPublishTarget implements Query
{
    public function __construct(
        public readonly int $id,
        public readonly bool $withSecrets = false,
    ) {
    }
}
