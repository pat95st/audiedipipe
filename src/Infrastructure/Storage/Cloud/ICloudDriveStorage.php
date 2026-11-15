<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\LocalStorage;
use AudiEdiPipe\Infrastructure\Storage\RemoteFile;
use AudiEdiPipe\Infrastructure\Storage\StorageAdapter;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

/**
 * iCloud Drive. Apple offers no public iCloud Drive API for servers, so the
 * files are read from the locally synchronised iCloud Drive folder (macOS:
 * ~/Library/Mobile Documents/com~apple~CloudDocs, Windows: iCloud for Windows).
 * Paths are relative to that folder.
 */
final class ICloudDriveStorage implements StorageAdapter
{
    private readonly string $base;

    private readonly LocalStorage $local;

    public function __construct(string $basePath, ?string $home = null)
    {
        $basePath = trim($basePath);
        if (str_starts_with($basePath, '~')) {
            $home ??= (string) (getenv('HOME') ?: (function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : ''));
            $basePath = rtrim($home, '/') . substr($basePath, 1);
        }
        $this->base = rtrim($basePath, '/');
        $this->local = new LocalStorage();
    }

    public function listFiles(string $path, bool $recursive = false): array
    {
        $this->assertBase();

        return array_map(
            fn (RemoteFile $file): RemoteFile => new RemoteFile($this->relative($file->path), $file->size, $file->modified),
            $this->local->listFiles($this->absolute($path), $recursive),
        );
    }

    public function download(string $remotePath, string $localPath): void
    {
        $this->local->download($this->absolute($remotePath), $localPath);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->assertBase();
        $this->local->upload($localPath, $this->absolute($remotePath));
    }

    public function delete(string $remotePath): void
    {
        $this->local->delete($this->absolute($remotePath));
    }

    public function test(string $path): void
    {
        $this->assertBase();
        $this->local->test($this->absolute($path));
    }

    public function absolute(string $path): string
    {
        $segments = array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $s): bool => $s !== '' && $s !== '.');
        if (in_array('..', $segments, true)) {
            throw new StorageException('Invalid path: ' . $path);
        }

        return $this->base . ($segments === [] ? '' : '/' . implode('/', $segments));
    }

    private function relative(string $absolute): string
    {
        return str_starts_with($absolute, $this->base . '/') ? substr($absolute, strlen($this->base)) : $absolute;
    }

    private function assertBase(): void
    {
        if ($this->base === '' || !is_dir($this->base)) {
            throw new StorageException(sprintf('iCloud Drive folder not found: %s. iCloud Drive must be synchronised to this machine (macOS or iCloud for Windows); adjust the path under Settings > Cloud storage.', $this->base));
        }
    }
}
