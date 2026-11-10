<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Job\Command;

use AudiEdiPipe\Cqrs\Command;

final class RetryJob implements Command
{
    public function __construct(
        public readonly int $jobId,
    ) {
    }
}
