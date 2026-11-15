<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\RemoteFile;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

/**
 * Dropbox (API v2). Paths are relative to the Dropbox root, or to the app
 * folder when the Dropbox app was created with "App folder" access.
 */
final class DropboxStorage extends CloudStorage
{
    private const API = 'https://api.dropboxapi.com/2';

    private const CONTENT = 'https://content.dropboxapi.com/2';

    /** Single request uploads are limited to 150 MB. */
    private const SIMPLE_UPLOAD_LIMIT = 140 * 1048576;

    /** Upload session chunks must be a multiple of 4 MiB. */
    private const CHUNK = 8 * 1048576;

    public function listFiles(string $path, bool $recursive = false): array
    {
        $data = $this->rpc('/files/list_folder', ['path' => self::dropboxPath($path), 'recursive' => $recursive, 'include_deleted' => false], 'list', $path);
        $files = [];
        while (true) {
            foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $entry) {
                if (is_array($entry) && ($entry['.tag'] ?? '') === 'file') {
                    $files[] = new RemoteFile((string) ($entry['path_display'] ?? $entry['path_lower'] ?? ''), (int) ($entry['size'] ?? 0), self::timestamp($entry['server_modified'] ?? null));
                }
            }
            if (($data['has_more'] ?? false) !== true || !is_string($data['cursor'] ?? null)) {
                break;
            }
            $data = $this->rpc('/files/list_folder/continue', ['cursor' => $data['cursor']], 'list', $path);
        }
        usort($files, static fn (RemoteFile $a, RemoteFile $b): int => strcmp($a->path, $b->path));

        return $files;
    }

    public function download(string $remotePath, string $localPath): void
    {
        $this->downloadTo(self::CONTENT . '/files/download', $localPath, $remotePath, [
            'Content-Type' => 'application/octet-stream',
            'Dropbox-API-Arg' => self::arg(['path' => self::dropboxPath($remotePath)]),
        ], 'POST', '');
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $size = (int) @filesize($localPath);
        $commit = ['path' => self::dropboxPath($remotePath), 'mode' => 'overwrite', 'autorename' => false, 'mute' => true];
        if ($size <= self::SIMPLE_UPLOAD_LIMIT) {
            self::open($localPath); // fail early with a readable message
            $response = $this->call('POST', self::CONTENT . '/files/upload', [
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => self::arg($commit),
            ], null, ['upload_file' => $localPath]);
            if (!$response->ok()) {
                $this->fail('upload', $response, $remotePath);
            }

            return;
        }

        $handle = self::open($localPath);

        try {
            $sessionId = '';
            $offset = 0;
            while ($offset < $size) {
                $chunk = self::readChunk($handle, self::CHUNK);
                $last = $offset + strlen($chunk) >= $size;
                [$endpoint, $arg] = match (true) {
                    $sessionId === '' => ['/files/upload_session/start', ['close' => false]],
                    $last => ['/files/upload_session/finish', ['cursor' => ['session_id' => $sessionId, 'offset' => $offset], 'commit' => $commit]],
                    default => ['/files/upload_session/append_v2', ['cursor' => ['session_id' => $sessionId, 'offset' => $offset], 'close' => false]],
                };
                $response = $this->call('POST', self::CONTENT . $endpoint, [
                    'Content-Type' => 'application/octet-stream',
                    'Dropbox-API-Arg' => self::arg($arg),
                ], $chunk);
                if (!$response->ok()) {
                    $this->fail('upload', $response, $remotePath);
                }
                if ($sessionId === '') {
                    $sessionId = (string) ($response->json()['session_id'] ?? '');
                    if ($sessionId === '') {
                        throw new StorageException('Dropbox did not return an upload session.');
                    }
                    if ($last) {
                        // The whole file fitted into the first chunk: finish without data.
                        $offset += strlen($chunk);
                        $this->finish($sessionId, $offset, $commit, $remotePath);

                        return;
                    }
                }
                $offset += strlen($chunk);
                if ($chunk === '') {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function delete(string $remotePath): void
    {
        $response = $this->call('POST', self::API . '/files/delete_v2', ['Content-Type' => 'application/json'], self::arg(['path' => self::dropboxPath($remotePath)]));
        if ($response->ok() || ($response->status === 409 && str_contains($response->body, 'not_found'))) {
            return;
        }
        $this->fail('delete', $response, $remotePath);
    }

    public function account(): string
    {
        $response = $this->call('POST', self::API . '/users/get_current_account', ['Content-Type' => 'application/json'], 'null');
        $data = $response->json();

        return (string) ($data['email'] ?? $data['name']['display_name'] ?? '');
    }

    /**
     * Dropbox uses "" for the root and "/dir/file" otherwise.
     */
    public static function dropboxPath(string $path): string
    {
        $path = self::normalize($path);

        return $path === '/' ? '' : $path;
    }

    /**
     * @param array<string, mixed> $commit
     */
    private function finish(string $sessionId, int $offset, array $commit, string $remotePath): void
    {
        $response = $this->call('POST', self::CONTENT . '/files/upload_session/finish', [
            'Content-Type' => 'application/octet-stream',
            'Dropbox-API-Arg' => self::arg(['cursor' => ['session_id' => $sessionId, 'offset' => $offset], 'commit' => $commit]),
        ], '');
        if (!$response->ok()) {
            $this->fail('upload', $response, $remotePath);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function rpc(string $endpoint, array $payload, string $action, string $path): array
    {
        return $this->callJson('POST', self::API . $endpoint, $payload, $action, $path);
    }

    /**
     * JSON for the Dropbox-API-Arg header. Non-ASCII characters must be escaped.
     *
     * @param array<string, mixed> $payload
     */
    private static function arg(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
