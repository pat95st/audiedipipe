<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\Cloud\ICloudDriveStorage;
use AudiEdiPipe\Infrastructure\Storage\StorageException;
use AudiEdiPipe\Tests\Support\TestCase;

final class ICloudDriveStorageTest extends TestCase
{
    public function test_paths_are_relative_to_the_icloud_folder(): void
    {
        $home = $this->tempDir();
        $base = $home . '/Library/Mobile Documents/com~apple~CloudDocs';
        mkdir($base . '/Podcast', 0775, true);
        file_put_contents($base . '/Podcast/ep.wav', 'wav');
        $storage = new ICloudDriveStorage('~/Library/Mobile Documents/com~apple~CloudDocs', $home);

        $files = $storage->listFiles('/Podcast');

        self::assertCount(1, $files);
        self::assertSame('/Podcast/ep.wav', $files[0]->path);
        $target = $this->tempDir() . '/copy.wav';
        $storage->download($files[0]->path, $target);
        self::assertSame('wav', file_get_contents($target));

        $storage->upload($target, '/Export/new.wav');
        self::assertFileExists($base . '/Export/new.wav');
    }

    public function test_missing_icloud_folder_gives_a_helpful_error(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('iCloud Drive folder not found');
        (new ICloudDriveStorage('/does/not/exist'))->test('/');
    }

    public function test_parent_directory_segments_are_rejected(): void
    {
        $this->expectException(StorageException::class);
        (new ICloudDriveStorage($this->tempDir()))->absolute('/../etc/passwd');
    }
}
