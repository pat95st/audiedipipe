<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Job\Command;

use AudiEdiPipe\Cqrs\Command;

final class ReviewJob implements Command
{
    public function __construct(
        public readonly int $jobId,
        public readonly int $userId,
        public readonly bool $approved,
        public readonly string $comment = '',
    ) {
    }
}
