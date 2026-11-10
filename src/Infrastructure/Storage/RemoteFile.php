<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

final class RemoteFile
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly int $modified,
    ) {
    }

    public function name(): string
    {
        return basename($this->path);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->path . '|' . $this->size . '|' . $this->modified);
    }
}
