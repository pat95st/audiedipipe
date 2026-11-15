<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\Cloud\BoxStorage;
use CURLFile;

final class BoxStorageTest extends CloudTestCase
{
    public function test_list_resolves_path_by_folder_ids(): void
    {
        $this->http
            ->queue(200, ['entries' => [['id' => '11', 'type' => 'folder', 'name' => 'podcast']]])
            ->queue(200, ['entries' => [
                ['id' => '21', 'type' => 'file', 'name' => 'ep.wav', 'size' => 99, 'content_modified_at' => '2026-04-01T08:00:00-07:00'],
                ['id' => '22', 'type' => 'web_link', 'name' => 'link'],
            ], 'next_marker' => 'm2'])
            ->queue(200, ['entries' => [['id' => '23', 'type' => 'file', 'name' => 'a.mp3', 'size' => 1]]])
            ->queue(200, '', [], 'WAV');
        $storage = new BoxStorage($this->http, $this->session());

        $files = $storage->listFiles('/Podcast');

        // Box names are case-insensitive.
        self::assertSame(['/Podcast/a.mp3', '/Podcast/ep.wav'], array_map(static fn ($f) => $f->path, $files));
        self::assertStringStartsWith('https://api.box.com/2.0/folders/0/items?', $this->http->requests[0]['url']);
        self::assertStringStartsWith('https://api.box.com/2.0/folders/11/items?', $this->http->requests[1]['url']);
        self::assertStringContainsString('marker=m2', $this->http->requests[2]['url']);

        $storage->download('/Podcast/ep.wav', $this->tempDir() . '/ep.wav');
        self::assertSame('https://api.box.com/2.0/files/21/content', $this->http->requests[3]['url']);
    }

    public function test_upload_new_version_of_existing_file(): void
    {
        $local = $this->tempDir() . '/ep.mp3';
        file_put_contents($local, 'mp3');
        $this->http
            ->queue(200, ['entries' => [['id' => '5', 'type' => 'file', 'name' => 'ep.mp3']]])
            ->queue(201, ['entries' => [['id' => '5']]]);
        (new BoxStorage($this->http, $this->session()))->upload($local, '/ep.mp3');

        $request = $this->http->requests[1];
        self::assertSame('https://upload.box.com/api/2.0/files/5/content', $request['url']);
        self::assertIsArray($request['body']);
        self::assertSame(['attributes', 'file'], array_keys($request['body']));
        self::assertInstanceOf(CURLFile::class, $request['body']['file']);
    }

    public function test_upload_creates_folders(): void
    {
        $local = $this->tempDir() . '/ep.mp3';
        file_put_contents($local, 'mp3');
        $this->http
            ->queue(200, ['entries' => []])          // root listing: "Export" missing
            ->queue(201, ['id' => '77'])             // create folder
            ->queue(200, ['entries' => []])          // folder 77 listing
            ->queue(201, ['entries' => [['id' => '8']]]);
        (new BoxStorage($this->http, $this->session()))->upload($local, '/Export/ep.mp3');

        self::assertSame(['name' => 'Export', 'parent' => ['id' => '0']], $this->http->jsonBody(1));
        self::assertSame('https://upload.box.com/api/2.0/files/content', $this->http->requests[3]['url']);
        $attributes = $this->http->requests[3]['body']['attributes'] ?? '';
        self::assertSame(['name' => 'ep.mp3', 'parent' => ['id' => '77']], json_decode(is_string($attributes) ? $attributes : '', true));
    }
}
