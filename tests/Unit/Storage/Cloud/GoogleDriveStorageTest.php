<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\Cloud\GoogleDriveStorage;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

final class GoogleDriveStorageTest extends CloudTestCase
{
    public function test_query_literals_are_escaped(): void
    {
        self::assertSame("'Rock \\'n\\' Roll'", GoogleDriveStorage::quote("Rock 'n' Roll"));
        self::assertSame("'a\\\\b'", GoogleDriveStorage::quote('a\\b'));
    }

    public function test_list_resolves_path_and_skips_folders_and_google_docs(): void
    {
        $this->http
            ->queue(200, ['files' => [['id' => 'F1', 'name' => 'Podcast', 'mimeType' => 'application/vnd.google-apps.folder']]])
            ->queue(200, ['files' => [
                ['id' => 'A', 'name' => 'ep1.wav', 'mimeType' => 'audio/wav', 'size' => '5000', 'modifiedTime' => '2026-03-01T12:00:00.000Z'],
                ['id' => 'D', 'name' => 'Notes', 'mimeType' => 'application/vnd.google-apps.document'],
                ['id' => 'S', 'name' => 'Sub', 'mimeType' => 'application/vnd.google-apps.folder'],
            ], 'nextPageToken' => 'p2'])
            ->queue(200, ['files' => [['id' => 'B', 'name' => 'ep0.mp3', 'mimeType' => 'audio/mpeg', 'size' => '10']]])
            ->queue(200, '', [], 'BYTES');
        $storage = new GoogleDriveStorage($this->http, $this->session());

        $files = $storage->listFiles('/Podcast');

        self::assertSame(['/Podcast/ep0.mp3', '/Podcast/ep1.wav'], array_map(static fn ($f) => $f->path, $files));
        self::assertSame(5000, $files[1]->size);
        parse_str((string) parse_url($this->http->requests[0]['url'], PHP_URL_QUERY), $first);
        self::assertSame("'root' in parents and name = 'Podcast' and trashed = false", $first['q']);
        parse_str((string) parse_url($this->http->requests[2]['url'], PHP_URL_QUERY), $page2);
        self::assertSame('p2', $page2['pageToken']);

        // The ID of a listed file is cached: download needs no further lookup.
        $target = $this->tempDir() . '/x.wav';
        $storage->download('/Podcast/ep1.wav', $target);
        self::assertStringStartsWith('https://www.googleapis.com/drive/v3/files/A?alt=media', $this->http->requests[3]['url']);
        self::assertSame('BYTES', file_get_contents($target));
    }

    public function test_missing_folder_is_reported(): void
    {
        $this->http->queue(200, ['files' => []]);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Google Drive folder not found: /Nope');
        (new GoogleDriveStorage($this->http, $this->session()))->listFiles('/Nope');
    }

    public function test_upload_creates_missing_folder_and_uses_resumable_session(): void
    {
        $local = $this->tempDir() . '/ep.mp3';
        file_put_contents($local, 'mp3data');
        $this->http
            ->queue(200, ['files' => []])                        // lookup "Export" in root: missing
            ->queue(200, ['id' => 'NEWFOLDER'])                  // create folder
            ->queue(200, ['files' => []])                        // lookup ep.mp3: missing
            ->queue(200, '', ['location' => 'https://upload.example/session1'])
            ->queue(200, ['id' => 'FILE1']);
        (new GoogleDriveStorage($this->http, $this->session()))->upload($local, '/Export/ep.mp3');

        self::assertSame(['name' => 'Export', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['root']], $this->http->jsonBody(1));
        self::assertStringStartsWith('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', $this->http->requests[3]['url']);
        self::assertSame(['name' => 'ep.mp3', 'parents' => ['NEWFOLDER']], $this->http->jsonBody(3));
        self::assertSame('7', $this->http->requests[3]['headers']['X-Upload-Content-Length']);
        self::assertSame('PUT', $this->http->requests[4]['method']);
        self::assertSame('https://upload.example/session1', $this->http->requests[4]['url']);
        self::assertSame($local, $this->http->requests[4]['options']['upload_file']);
    }
}
