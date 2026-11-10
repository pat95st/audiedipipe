<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

final class Episode
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $mimeType,
        public readonly string $title,
        public readonly string $description,
        public readonly int $duration,
        public readonly ?int $jobId = null,
        public readonly ?int $episodeNumber = null,
        public readonly ?int $seasonNumber = null,
        public readonly bool $explicit = false,
        public readonly string $imageUrl = '',
    ) {
    }
}
