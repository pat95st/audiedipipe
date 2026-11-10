<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Job\Command;

use AudiEdiPipe\Cqrs\Command;

final class SubmitManualEdit implements Command
{
    /**
     * @param array<mixed> $cuts
     * @param array<mixed> $metadata
     */
    public function __construct(
        public readonly int $jobId,
        public readonly int $userId,
        public readonly array $cuts,
        public readonly array $metadata,
    ) {
    }
}
