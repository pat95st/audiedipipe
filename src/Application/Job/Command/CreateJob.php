<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Job\Command;

use AudiEdiPipe\Cqrs\Command;

final class CreateJob implements Command
{
    /**
     * @param array<mixed> $metadata
     */
    public function __construct(
        public readonly int $pipelineId,
        public readonly string $originalName,
        public readonly string $inputPath,
        public readonly string $origin = 'upload',
        public readonly ?int $sourceId = null,
        public readonly ?int $userId = null,
        public readonly array $metadata = [],
    ) {
    }
}
