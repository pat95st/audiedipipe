<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\RemoteFile;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

/**
 * Google Drive (API v3). Drive is ID based; paths ("/Podcast/Inbox") are
 * resolved folder by folder starting at "My Drive".
 */
final class GoogleDriveStorage extends CloudStorage
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    private const FOLDER = 'application/vnd.google-apps.folder';

    private const FIELDS = 'id,name,mimeType,size,modifiedTime';

    /** @var array<string, array{id: string, folder: bool}> normalised path => item */
    private array $items = ['/' => ['id' => 'root', 'folder' => true]];

    public function listFiles(string $path, bool $recursive = false): array
    {
        $path = self::normalize($path);
        $folder = $this->resolve($path);
        if ($folder === null || !$folder['folder']) {
            throw new StorageException('Google Drive folder not found: ' . $path);
        }
        $files = $this->listFolder($folder['id'], $path, $recursive);
        usort($files, static fn (RemoteFile $a, RemoteFile $b): int => strcmp($a->path, $b->path));

        return $files;
    }

    public function download(string $remotePath, string $localPath): void
    {
        $item = $this->resolve(self::normalize($remotePath));
        if ($item === null || $item['folder']) {
            throw new StorageException('Google Drive file not found: ' . $remotePath);
        }
        $this->downloadTo(self::API . '/files/' . rawurlencode($item['id']) . '?alt=media&supportsAllDrives=true', $localPath, $remotePath);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $remotePath = self::normalize($remotePath);
        $size = (int) @filesize($localPath);
        self::open($localPath);
        $parent = $this->resolve(dirname($remotePath), true);
        if ($parent === null) {
            throw new StorageException('Cannot create Google Drive folder: ' . dirname($remotePath));
        }
        $existing = $this->child($parent['id'], basename($remotePath));
        $headers = [
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type' => 'application/octet-stream',
            'X-Upload-Content-Length' => (string) $size,
        ];
        // Resumable upload: works for any size and replaces the content of an existing file.
        $init = $existing !== null && !$existing['folder']
            ? $this->call('PATCH', self::UPLOAD . '/files/' . rawurlencode($existing['id']) . '?uploadType=resumable&supportsAllDrives=true', $headers, '{}')
            : $this->call('POST', self::UPLOAD . '/files?uploadType=resumable&supportsAllDrives=true', $headers, (string) json_encode(['name' => basename($remotePath), 'parents' => [$parent['id']]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $session = $init->headers['location'] ?? '';
        if (!$init->ok() || $session === '') {
            $this->fail('upload', $init, $remotePath);
        }
        $response = $this->http->request('PUT', $session, ['Content-Type' => 'application/octet-stream'], null, ['upload_file' => $localPath, 'timeout' => 3600]);
        if (!$response->ok()) {
            $this->fail('upload', $response, $remotePath);
        }
        $id = (string) ($response->json()['id'] ?? '');
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
        // Move to the trash instead of deleting permanently.
        $response = $this->call('PATCH', self::API . '/files/' . rawurlencode($item['id']) . '?supportsAllDrives=true', ['Content-Type' => 'application/json'], '{"trashed":true}');
        if (!$response->ok() && $response->status !== 404) {
            $this->fail('delete', $response, $remotePath);
        }
        unset($this->items[$remotePath]);
    }

    public function account(): string
    {
        $data = $this->call('GET', self::API . '/about?fields=user(emailAddress,displayName)')->json();

        return (string) ($data['user']['emailAddress'] ?? $data['user']['displayName'] ?? '');
    }

    /**
     * Escape a value for a Drive search query string literal.
     */
    public static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * @return list<RemoteFile>
     */
    private function listFolder(string $folderId, string $path, bool $recursive): array
    {
        $files = [];
        foreach ($this->query(self::quote($folderId) . ' in parents and trashed = false', $path) as $item) {
            $itemPath = self::join($path, (string) $item['name']);
            $isFolder = ($item['mimeType'] ?? '') === self::FOLDER;
            $this->items[$itemPath] = ['id' => (string) $item['id'], 'folder' => $isFolder];
            if ($isFolder) {
                if ($recursive) {
                    array_push($files, ...$this->listFolder((string) $item['id'], $itemPath, true));
                }

                continue;
            }
            // Google Docs/Sheets etc. have no binary content.
            if (str_starts_with((string) ($item['mimeType'] ?? ''), 'application/vnd.google-apps.')) {
                continue;
            }
            $files[] = new RemoteFile($itemPath, (int) ($item['size'] ?? 0), self::timestamp($item['modifiedTime'] ?? null));
        }

        return $files;
    }

    /**
     * Resolve a normalised path to a Drive item, optionally creating missing folders.
     *
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
                $created = $this->callJson('POST', self::API . '/files?supportsAllDrives=true&fields=id', ['name' => $segment, 'mimeType' => self::FOLDER, 'parents' => [$item['id']]], 'create folder', $next);
                $found = ['id' => (string) ($created['id'] ?? ''), 'folder' => true];
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
    private function child(string $parentId, string $name): ?array
    {
        $matches = $this->query(self::quote($parentId) . ' in parents and name = ' . self::quote($name) . ' and trashed = false', $name);
        // Prefer folders when a file and a folder share the same name.
        usort($matches, static fn (array $a, array $b): int => (int) (($b['mimeType'] ?? '') === self::FOLDER) <=> (int) (($a['mimeType'] ?? '') === self::FOLDER));
        $first = $matches[0] ?? null;

        return $first === null ? null : ['id' => (string) $first['id'], 'folder' => ($first['mimeType'] ?? '') === self::FOLDER];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function query(string $q, string $path): array
    {
        $items = [];
        $pageToken = '';
        do {
            $params = [
                'q' => $q,
                'fields' => 'nextPageToken,files(' . self::FIELDS . ')',
                'pageSize' => 1000,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }
            $data = $this->callJson('GET', self::API . '/files?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), null, 'list', $path);
            foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
                if (is_array($file) && isset($file['id'], $file['name'])) {
                    /** @var array<string, mixed> $file */
                    $items[] = $file;
                }
            }
            $pageToken = is_string($data['nextPageToken'] ?? null) ? $data['nextPageToken'] : '';
        } while ($pageToken !== '');

        return $items;
    }
}
