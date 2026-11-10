<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Template\Command;

use AudiEdiPipe\Cqrs\Command;

final class SavePreset implements Command
{
    /**
     * @param array<mixed> $params
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $effectType,
        public readonly string $name,
        public readonly array $params,
    ) {
    }
}
