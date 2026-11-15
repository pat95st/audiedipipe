<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\RemoteFile;
use AudiEdiPipe\Infrastructure\Storage\StorageException;
use CURLFile;

/**
 * Box (Content API 2.0). Box is ID based; paths are resolved folder by folder
 * starting at "All Files" (folder 0).
 */
final class BoxStorage extends CloudStorage
{
    private const API = 'https://api.box.com/2.0';

    private const UPLOAD = 'https://upload.box.com/api/2.0';

    /** Larger files use the chunked upload API. */
    private const SIMPLE_UPLOAD_LIMIT = 50 * 1048576;

    /** @var array<string, array{id: string, folder: bool}> normalised path => item */
    private array $items = ['/' => ['id' => '0', 'folder' => true]];

    /** @var array<string, list<array<string, mixed>>> folder id => entries */
    private array $listings = [];

    public function listFiles(string $path, bool $recursive = false): array
    {
        $path = self::normalize($path);
        $folder = $this->resolve($path);
        if ($folder === null || !$folder['folder']) {
            throw new StorageException('Box folder not found: ' . $path);
        }
        $files = $this->listFolder($folder['id'], $path, $recursive);
        usort($files, static fn (RemoteFile $a, RemoteFile $b): int => strcmp($a->path, $b->path));

        return $files;
    }

    public function download(string $remotePath, string $localPath): void
    {
        $item = $this->resolve(self::normalize($remotePath));
        if ($item === null || $item['folder']) {
            throw new StorageException('Box file not found: ' . $remotePath);
        }
        // Box redirects to a pre-signed download URL.
        $this->downloadTo(self::API . '/files/' . rawurlencode($item['id']) . '/content', $localPath, $remotePath);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $remotePath = self::normalize($remotePath);
        $size = (int) @filesize($localPath);
        fclose(self::open($localPath));
        $parent = $this->resolve(dirname($remotePath), true);
        if ($parent === null) {
            throw new StorageException('Cannot create Box folder: ' . dirname($remotePath));
        }
        $name = basename($remotePath);
        $existing = $this->child($parent['id'], $name);
        $fileId = $existing !== null && !$existing['folder'] ? $existing['id'] : null;

        $id = $size > self::SIMPLE_UPLOAD_LIMIT
            ? $this->chunkedUpload($localPath, $size, $parent['id'], $name, $fileId, $remotePath)
            : $this->simpleUpload($localPath, $parent['id'], $name, $fileId, $remotePath);
        unset($this->listings[$parent['id']]);
        if ($id !== '') {
            $this->items[$remotePath] = ['id' => $id, 'folder' => false];
        }
    }

    public function delete(string $remotePath): void
    {
        $remotePath = self::normalize($remotePath);
        $item = $this->resolve($remotePath);
        if ($item === null) {
            return;
        }
        // Deleted files go to the Box trash.
        $response = $this->call('DELETE', self::API . ($item['folder'] ? '/folders/' : '/files/') . rawurlencode($item['id']));
        if (!$response->ok() && $response->status !== 404) {
            $this->fail('delete', $response, $remotePath);
        }
        unset($this->items[$remotePath]);
        $this->listings = [];
    }

    public function account(): string
    {
        $data = $this->call('GET', self::API . '/users/me?fields=login,name')->json();

        return (string) ($data['login'] ?? $data['name'] ?? '');
    }

    /**
     * @return list<RemoteFile>
     */
    private function listFolder(string $folderId, string $path, bool $recursive): array
    {
        $files = [];
        foreach ($this->entries($folderId, $path) as $entry) {
            $itemPath = self::join($path, (string) $entry['name']);
            $isFolder = $entry['type'] === 'folder';
            $this->items[$itemPath] = ['id' => (string) $entry['id'], 'folder' => $isFolder];
            if ($isFolder) {
                if ($recursive) {
                    array_push($files, ...$this->listFolder((string) $entry['id'], $itemPath, true));
                }

                continue;
            }
            if ($entry['type'] === 'file') {
                $files[] = new RemoteFile($itemPath, (int) ($entry['size'] ?? 0), self::timestamp($entry['content_modified_at'] ?? $entry['modified_at'] ?? null));
            }
        }

        return $files;
    }

    /**
     * All entries of a folder (marker based pagination).
     *
     * @return list<array<string, mixed>>
     */
    private function entries(string $folderId, string $path): array
    {
        if (isset($this->listings[$folderId])) {
            return $this->listings[$folderId];
        }
        $entries = [];
        $marker = '';
        do {
            $params = ['fields' => 'id,type,name,size,modified_at,content_modified_at', 'limit' => 1000, 'usemarker' => 'true'];
            if ($marker !== '') {
                $params['marker'] = $marker;
            }
            $response = $this->call('GET', self::API . '/folders/' . rawurlencode($folderId) . '/items?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
            if ($response->status === 404) {
                throw new StorageException('Box folder not found: ' . $path);
            }
            if (!$response->ok()) {
                $this->fail('list', $response, $path);
            }
            $data = $response->json();
            foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $entry) {
                if (is_array($entry) && isset($entry['id'], $entry['type'], $entry['name'])) {
                    /** @var array<string, mixed> $entry */
                    $entries[] = $entry;
                }
            }
            $marker = is_string($data['next_marker'] ?? null) ? $data['next_marker'] : '';
        } while ($marker !== '');

        return $this->listings[$folderId] = $entries;
    }

    /**
     * @return array{id: string, folder: bool}|null
     */
    private function resolve(string $path, bool $createFolders = false): ?array
    {
        $path = self::normalize($path);
        if (isset($this->items[$path])) {
            return $this->items[$path];
        }
        $current = '/';
        $item = $this->items['/'];
        foreach (array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '')) as $segment) {
            $next = self::join($current, $segment);
            $found = $this->items[$next] ?? $this->child($item['id'], $segment);
            if ($found === null) {
                if (!$createFolders) {
                    return null;
                }
                $found = ['id' => $this->createFolder($item['id'], $segment, $next), 'folder' => true];
            }
            $this->items[$next] = $found;
            $item = $found;
            $current = $next;
        }

        return $item;
    }

    /**
     * @return array{id: string, folder: bool}|null
     */
    private function child(string $folderId, string $name): ?array
    {
        $file = null;
        foreach ($this->entries($folderId, $name) as $entry) {
            // Box names are case-insensitive.
            if (mb_strtolower((string) $entry['name']) !== mb_strtolower($name)) {
                continue;
            }
            if ($entry['type'] === 'folder') {
                return ['id' => (string) $entry['id'], 'folder' => true];
            }
            if ($entry['type'] === 'file') {
                $file = ['id' => (string) $entry['id'], 'folder' => false];
            }
        }

        return $file;
    }

    private function createFolder(string $parentId, string $name, string $path): string
    {
        $response = $this->call('POST', self::API . '/folders?fields=id', ['Content-Type' => 'application/json'], (string) json_encode(['name' => $name, 'parent' => ['id' => $parentId]], JSON_UNESCAPED_UNICODE));
        $data = $response->json();
        if ($response->status === 409) {
            // Created concurrently: use the existing folder.
            $conflict = $data['context_info']['conflicts'][0]['id'] ?? $data['context_info']['conflicts']['id'] ?? null;
            if (is_scalar($conflict)) {
                return (string) $conflict;
            }
        }
        if (!$response->ok()) {
            $this->fail('create folder', $response, $path);
        }
        unset($this->listings[$parentId]);

        return (string) ($data['id'] ?? '');
    }

    private function simpleUpload(string $localPath, string $parentId, string $name, ?string $fileId, string $remotePath): string
    {
        $url = $fileId !== null ? self::UPLOAD . '/files/' . rawurlencode($fileId) . '/content' : self::UPLOAD . '/files/content';
        $attributes = $fileId !== null ? ['name' => $name] : ['name' => $name, 'parent' => ['id' => $parentId]];
        // "attributes" must precede "file" in the multipart body.
        $response = $this->call('POST', $url, [], [
            'attributes' => (string) json_encode($attributes, JSON_UNESCAPED_UNICODE),
            'file' => new CURLFile($localPath, 'application/octet-stream', $name),
        ]);
        if (!$response->ok()) {
            $this->fail('upload', $response, $remotePath);
        }

        return (string) ($response->json()['entries'][0]['id'] ?? '');
    }

    private function chunkedUpload(string $localPath, int $size, string $parentId, string $name, ?string $fileId, string $remotePath): string
    {
        $session = $fileId !== null
            ? $this->callJson('POST', self::UPLOAD . '/files/' . rawurlencode($fileId) . '/upload_sessions', ['file_size' => $size, 'file_name' => $name], 'upload', $remotePath)
            : $this->callJson('POST', self::UPLOAD . '/files/upload_sessions', ['folder_id' => $parentId, 'file_size' => $size, 'file_name' => $name], 'upload', $remotePath);
        $partSize = (int) ($session['part_size'] ?? 0);
        $uploadUrl = (string) ($session['session_endpoints']['upload_part'] ?? '');
        $commitUrl = (string) ($session['session_endpoints']['commit'] ?? '');
        if ($partSize <= 0 || $uploadUrl === '' || $commitUrl === '') {
            throw new StorageException('Box did not return a valid upload session.');
        }

        $parts = [];
        $handle = self::open($localPath);
        $whole = hash_init('sha1');

        try {
            $offset = 0;
            while ($offset < $size) {
                $chunk = self::readChunk($handle, $partSize);
                if ($chunk === '') {
                    throw new StorageException('Local file changed during upload: ' . $localPath);
                }
                hash_update($whole, $chunk);
                $end = $offset + strlen($chunk) - 1;
                $response = $this->call('PUT', $uploadUrl, [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $end, $size),
                    'Digest' => 'sha=' . base64_encode(sha1($chunk, true)),
                ], $chunk);
                if (!$response->ok()) {
                    $this->call('DELETE', (string) ($session['session_endpoints']['abort'] ?? $uploadUrl));
                    $this->fail('upload', $response, $remotePath);
                }
                $part = $response->json()['part'] ?? null;
                if (is_array($part)) {
                    $parts[] = $part;
                }
                $offset = $end + 1;
            }
        } finally {
            fclose($handle);
        }

        $digest = 'sha=' . base64_encode(hash_final($whole, true));
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $response = $this->call('POST', $commitUrl, ['Content-Type' => 'application/json', 'Digest' => $digest], (string) json_encode(['parts' => $parts], JSON_UNESCAPED_SLASHES));
            if ($response->status !== 202) {
                break;
            }
            // Box is still processing the parts.
            sleep(max(1, min(10, (int) ($response->headers['retry-after'] ?? 2))));
        }
        if (!$response->ok()) {
            $this->fail('upload', $response, $remotePath);
        }

        return (string) ($response->json()['entries'][0]['id'] ?? '');
    }
}
