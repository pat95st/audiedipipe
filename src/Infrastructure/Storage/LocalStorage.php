<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Local filesystem (also usable for mounted network shares).
 */
final class LocalStorage implements StorageAdapter
{
    public function listFiles(string $path, bool $recursive = false): array
    {
        if (!is_dir($path)) {
            throw new StorageException('Directory not found: ' . $path);
        }
        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
            : new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        $files = [];

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if ($info->isFile()) {
                $files[] = new RemoteFile($info->getPathname(), (int) $info->getSize(), (int) $info->getMTime());
            }
        }
        usort($files, static fn (RemoteFile $a, RemoteFile $b): int => strcmp($a->path, $b->path));

        return $files;
    }

    public function download(string $remotePath, string $localPath): void
    {
        if (!@copy($remotePath, $localPath)) {
            throw new StorageException('Copy failed: ' . $remotePath);
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $dir = dirname($remotePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new StorageException('Cannot create directory: ' . $dir);
        }
        if (!@copy($localPath, $remotePath)) {
            throw new StorageException('Copy failed: ' . $remotePath);
        }
    }

    public function delete(string $remotePath): void
    {
        if (is_file($remotePath) && !@unlink($remotePath)) {
            throw new StorageException('Delete failed: ' . $remotePath);
        }
    }

    public function test(string $path): void
    {
        if (!is_dir($path) || !is_readable($path)) {
            throw new StorageException('Directory not readable: ' . $path);
        }
    }
}
