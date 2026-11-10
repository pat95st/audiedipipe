<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Settings\Command;

use AudiEdiPipe\Cqrs\Command;

final class SaveSettings implements Command
{
    /**
     * @param array<mixed> $values
     */
    public function __construct(
        public readonly array $values,
    ) {
    }
}
