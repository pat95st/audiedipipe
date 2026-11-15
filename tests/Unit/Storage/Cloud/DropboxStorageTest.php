<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\Cloud\DropboxStorage;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

final class DropboxStorageTest extends CloudTestCase
{
    public function test_root_path_is_empty_string(): void
    {
        self::assertSame('', DropboxStorage::dropboxPath('/'));
        self::assertSame('', DropboxStorage::dropboxPath(''));
        self::assertSame('/Podcast/In', DropboxStorage::dropboxPath('Podcast//In/'));
    }

    public function test_list_files_follows_cursor_and_skips_folders(): void
    {
        $this->http
            ->queue(200, ['entries' => [
                ['.tag' => 'folder', 'path_display' => '/Podcast/Sub'],
                ['.tag' => 'file', 'path_display' => '/Podcast/b.wav', 'size' => 2048, 'server_modified' => '2026-01-02T10:00:00Z'],
            ], 'has_more' => true, 'cursor' => 'c1'])
            ->queue(200, ['entries' => [
                ['.tag' => 'file', 'path_display' => '/Podcast/a.mp3', 'size' => 1024, 'server_modified' => '2026-01-01T10:00:00Z'],
            ], 'has_more' => false]);
        $storage = new DropboxStorage($this->http, $this->session());

        $files = $storage->listFiles('/Podcast', true);

        self::assertSame(['/Podcast/a.mp3', '/Podcast/b.wav'], array_map(static fn ($f) => $f->path, $files));
        self::assertSame(1024, $files[0]->size);
        self::assertSame(strtotime('2026-01-01T10:00:00Z'), $files[0]->modified);
        self::assertSame('https://api.dropboxapi.com/2/files/list_folder', $this->http->requests[0]['url']);
        self::assertSame(['path' => '/Podcast', 'recursive' => true, 'include_deleted' => false], $this->http->jsonBody(0));
        self::assertSame(['cursor' => 'c1'], $this->http->jsonBody(1));
    }

    public function test_download_passes_path_in_api_arg_header(): void
    {
        $this->http->queue(200, '', [], 'AUDIO');
        $target = $this->tempDir() . '/out.wav';
        (new DropboxStorage($this->http, $this->session()))->download('/Podcast/Ä.wav', $target);

        self::assertSame('AUDIO', file_get_contents($target));
        self::assertSame('https://content.dropboxapi.com/2/files/download', $this->http->requests[0]['url']);
        // Header values must be ASCII: non-ASCII characters are JSON escaped.
        self::assertSame('{"path":"/Podcast/\\u00c4.wav"}', $this->http->requests[0]['headers']['Dropbox-API-Arg']);
    }

    public function test_failed_download_removes_partial_file(): void
    {
        $this->http->queue(409, ['error_summary' => 'path/not_found/']);
        $target = $this->tempDir() . '/out.wav';

        try {
            (new DropboxStorage($this->http, $this->session()))->download('/missing.wav', $target);
            self::fail('Expected exception');
        } catch (StorageException $e) {
            self::assertStringContainsString('path/not_found', $e->getMessage());
        }
        self::assertFileDoesNotExist($target);
    }

    public function test_small_upload_overwrites(): void
    {
        $local = $this->tempDir() . '/ep.mp3';
        file_put_contents($local, 'data');
        $this->http->queue(200, ['id' => 'id:1']);

        (new DropboxStorage($this->http, $this->session()))->upload($local, '/Export/ep.mp3');

        $request = $this->http->requests[0];
        self::assertSame('https://content.dropboxapi.com/2/files/upload', $request['url']);
        self::assertSame($local, $request['options']['upload_file']);
        self::assertSame(['path' => '/Export/ep.mp3', 'mode' => 'overwrite', 'autorename' => false, 'mute' => true], json_decode($request['headers']['Dropbox-API-Arg'], true));
    }

    public function test_delete_ignores_missing_files(): void
    {
        $this->http->queue(409, ['error_summary' => 'path_lookup/not_found/..']);
        (new DropboxStorage($this->http, $this->session()))->delete('/gone.wav');
        self::assertSame(0, $this->http->remaining());
    }
}
