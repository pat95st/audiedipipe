<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\Cloud\OneDriveStorage;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

final class OneDriveStorageTest extends CloudTestCase
{
    public function test_item_urls_are_path_addressed_and_encoded(): void
    {
        self::assertSame('https://graph.microsoft.com/v1.0/me/drive/root/children', OneDriveStorage::itemUrl('/', 'children'));
        self::assertSame('https://graph.microsoft.com/v1.0/me/drive/root:/Pod%20cast/%23in:/children', OneDriveStorage::itemUrl('Pod cast/#in/', 'children'));
        self::assertSame('https://graph.microsoft.com/v1.0/me/drive/root:/a/b.wav:', OneDriveStorage::itemUrl('/a/b.wav'));
    }

    public function test_list_follows_next_link_and_recurses(): void
    {
        $this->http
            ->queue(200, ['value' => [
                ['name' => 'Sub', 'folder' => ['childCount' => 1]],
                ['name' => 'b.wav', 'file' => [], 'size' => 7, 'lastModifiedDateTime' => '2026-02-01T00:00:00Z'],
            ], '@odata.nextLink' => 'https://graph.microsoft.com/next'])
            ->queue(200, ['value' => [['name' => 'a.wav', 'file' => [], 'size' => 1]]])  // Sub/ (recursion)
            ->queue(200, ['value' => [['name' => 'c.wav', 'file' => [], 'size' => 3]]]); // next page of /In
        $files = (new OneDriveStorage($this->http, $this->session()))->listFiles('/In', true);

        self::assertSame(['/In/Sub/a.wav', '/In/b.wav', '/In/c.wav'], array_map(static fn ($f) => $f->path, $files));
        self::assertSame('https://graph.microsoft.com/next', $this->http->requests[2]['url']);
        self::assertStringStartsWith('https://graph.microsoft.com/v1.0/me/drive/root:/In/Sub:/children', $this->http->requests[1]['url']);
    }

    public function test_missing_folder(): void
    {
        $this->http->queue(404, ['error' => ['code' => 'itemNotFound', 'message' => 'Item not found']]);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('OneDrive folder not found: /x');
        (new OneDriveStorage($this->http, $this->session()))->listFiles('/x');
    }

    public function test_upload_uses_session_with_content_range_and_without_bearer_token(): void
    {
        $local = $this->tempDir() . '/ep.mp3';
        file_put_contents($local, str_repeat('x', 10));
        $this->http
            ->queue(200, ['uploadUrl' => 'https://upload.onedrive.example/s1'])
            ->queue(201, ['id' => 'X']);
        (new OneDriveStorage($this->http, $this->session()))->upload($local, '/Export/ep.mp3');

        self::assertSame('https://graph.microsoft.com/v1.0/me/drive/root:/Export/ep.mp3:/createUploadSession', $this->http->requests[0]['url']);
        self::assertSame(['item' => ['@microsoft.graph.conflictBehavior' => 'replace']], $this->http->jsonBody(0));
        self::assertSame('bytes 0-9/10', $this->http->requests[1]['headers']['Content-Range']);
        self::assertArrayNotHasKey('Authorization', $this->http->requests[1]['headers']);
    }
}
