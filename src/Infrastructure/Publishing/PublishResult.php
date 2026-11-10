<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

final class PublishResult
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $url = '',
        public readonly string $message = '',
    ) {
    }
}
