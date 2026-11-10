<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

/**
 * Common interface for all storage backends (watch sources and export destinations).
 */
interface StorageAdapter
{
    /**
     * List files (not directories) in a directory.
     *
     * @return list<RemoteFile>
     */
    public function listFiles(string $path, bool $recursive = false): array;

    public function download(string $remotePath, string $localPath): void;

    public function upload(string $localPath, string $remotePath): void;

    public function delete(string $remotePath): void;

    /**
     * Check connectivity; throws StorageException with a readable message on failure.
     */
    public function test(string $path): void;
}
