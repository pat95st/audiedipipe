<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Template\Query;

use AudiEdiPipe\Cqrs\Query;

final class ListPresets implements Query
{
    public function __construct(
        public readonly ?string $effectType = null,
    ) {
    }
}
