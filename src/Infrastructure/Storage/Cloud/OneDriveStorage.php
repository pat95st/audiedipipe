<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\RemoteFile;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

/**
 * OneDrive / OneDrive for Business via Microsoft Graph. Paths are relative to the drive root.
 */
final class OneDriveStorage extends CloudStorage
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Upload session fragments must be a multiple of 320 KiB (and below 60 MiB). */
    private const CHUNK = 32 * 327680;

    public function listFiles(string $path, bool $recursive = false): array
    {
        $path = self::normalize($path);
        $files = $this->listFolder($path, $recursive);
        usort($files, static fn (RemoteFile $a, RemoteFile $b): int => strcmp($a->path, $b->path));

        return $files;
    }

    public function download(string $remotePath, string $localPath): void
    {
        // Graph answers with a redirect to a pre-authenticated download URL, which curl follows.
        $this->downloadTo(self::itemUrl($remotePath, 'content'), $localPath, $remotePath);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $size = (int) @filesize($localPath);
        $handle = self::open($localPath);

        try {
            if ($size === 0) {
                // Upload sessions do not accept empty files.
                $response = $this->call('PUT', self::itemUrl($remotePath, 'content'), ['Content-Type' => 'application/octet-stream'], '');
                if (!$response->ok()) {
                    $this->fail('upload', $response, $remotePath);
                }

                return;
            }
            // Missing parent folders are created automatically for path based uploads.
            $session = $this->callJson('POST', self::itemUrl($remotePath, 'createUploadSession'), ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']], 'upload', $remotePath);
            $uploadUrl = is_string($session['uploadUrl'] ?? null) ? $session['uploadUrl'] : '';
            if ($uploadUrl === '') {
                throw new StorageException('OneDrive did not return an upload session.');
            }
            $offset = 0;
            while ($offset < $size) {
                $chunk = self::readChunk($handle, self::CHUNK);
                if ($chunk === '') {
                    throw new StorageException('Local file changed during upload: ' . $localPath);
                }
                $end = $offset + strlen($chunk) - 1;
                // The upload URL is pre-authenticated; it must not receive the bearer token.
                $response = $this->http->request('PUT', $uploadUrl, [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $end, $size),
                ], $chunk, ['timeout' => 900]);
                if (!$response->ok()) {
                    $this->http->request('DELETE', $uploadUrl, [], null, ['timeout' => 30]);
                    $this->fail('upload', $response, $remotePath);
                }
                $offset = $end + 1;
            }
        } finally {
            fclose($handle);
        }
    }

    public function delete(string $remotePath): void
    {
        $response = $this->call('DELETE', self::itemUrl($remotePath));
        if (!$response->ok() && $response->status !== 404) {
            $this->fail('delete', $response, $remotePath);
        }
    }

    public function account(): string
    {
        $data = $this->call('GET', self::GRAPH . '/me?$select=userPrincipalName,mail,displayName')->json();

        return (string) ($data['mail'] ?? $data['userPrincipalName'] ?? $data['displayName'] ?? '');
    }

    /**
     * Path addressed drive item URL, e.g. /me/drive/root:/Podcast/in.wav:/content
     */
    public static function itemUrl(string $path, string $action = ''): string
    {
        $path = self::normalize($path);
        $base = self::GRAPH . '/me/drive/root';
        if ($path !== '/') {
            $base .= ':' . implode('/', array_map('rawurlencode', explode('/', $path))) . ':';
        }

        return $action === '' ? $base : $base . '/' . $action;
    }

    /**
     * @return list<RemoteFile>
     */
    private function listFolder(string $path, bool $recursive): array
    {
        $files = [];
        $url = self::itemUrl($path, 'children') . '?$top=999&$select=name,size,lastModifiedDateTime,file,folder';
        while ($url !== '') {
            $response = $this->call('GET', $url);
            if ($response->status === 404) {
                throw new StorageException('OneDrive folder not found: ' . $path);
            }
            if (!$response->ok()) {
                $this->fail('list', $response, $path);
            }
            $data = $response->json();
            foreach (is_array($data['value'] ?? null) ? $data['value'] : [] as $item) {
                if (!is_array($item) || !is_string($item['name'] ?? null)) {
                    continue;
                }
                $itemPath = self::join($path, $item['name']);
                if (isset($item['folder'])) {
                    if ($recursive) {
                        array_push($files, ...$this->listFolder($itemPath, true));
                    }

                    continue;
                }
                if (isset($item['file'])) {
                    $files[] = new RemoteFile($itemPath, (int) ($item['size'] ?? 0), self::timestamp($item['lastModifiedDateTime'] ?? null));
                }
            }
            $url = is_string($data['@odata.nextLink'] ?? null) ? $data['@odata.nextLink'] : '';
        }

        return $files;
    }
}
