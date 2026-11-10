<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Command;

use AudiEdiPipe\Cqrs\Command;

final class SavePipeline implements Command
{
    /**
     * @param array<mixed> $stages
     * @param array<mixed> $notifications
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $description,
        public readonly bool $enabled,
        public readonly ?int $sourceId,
        public readonly ?int $metadataTemplateId,
        public readonly array $stages,
        public readonly array $notifications,
        public readonly ?int $userId = null,
    ) {
    }
}
