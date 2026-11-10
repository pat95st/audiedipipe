<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Job\Query;

use AudiEdiPipe\Cqrs\Query;

final class ListJobs implements Query
{
    /**
     * @param array<mixed>|null $statuses
     */
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?int $pipelineId = null,
        public readonly int $limit = 50,
        public readonly int $offset = 0,
        public readonly ?array $statuses = null,
    ) {
    }
}
