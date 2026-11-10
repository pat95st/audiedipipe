<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Storage;

use AudiEdiPipe\Infrastructure\Storage\FileFilter;
use AudiEdiPipe\Infrastructure\Storage\RemoteFile;
use PHPUnit\Framework\TestCase;

final class FileFilterTest extends TestCase
{
    public function test_accepts_matching_extension(): void
    {
        $filter = new FileFilter(['extensions' => 'wav, flac']);
        self::assertTrue($filter->accepts($this->file('/in/episode.wav')));
        self::assertFalse($filter->accepts($this->file('/in/cover.png')));
    }

    public function test_hidden_and_partial_files_are_ignored(): void
    {
        $filter = new FileFilter([]);
        self::assertFalse($filter->accepts($this->file('/in/.hidden.wav')));
        self::assertFalse($filter->accepts($this->file('/in/upload.wav.part')));
        self::assertFalse($filter->accepts($this->file('/in/upload.tmp')));
    }

    public function test_include_and_exclude_globs(): void
    {
        $filter = new FileFilter(['include' => 'ep-*', 'exclude' => '*-draft*']);
        self::assertTrue($filter->accepts($this->file('/in/ep-001.wav')));
        self::assertFalse($filter->accepts($this->file('/in/ep-001-draft.wav')));
        self::assertFalse($filter->accepts($this->file('/in/news.wav')));
    }

    public function test_regex_filter(): void
    {
        $filter = new FileFilter(['regex' => '^ep\d{3}']);
        self::assertTrue($filter->accepts($this->file('/in/ep042.wav')));
        self::assertFalse($filter->accepts($this->file('/in/episode.wav')));
    }

    public function test_minimum_size(): void
    {
        $filter = new FileFilter(['min_size_kb' => 100]);
        self::assertFalse($filter->accepts($this->file('/in/tiny.wav', size: 10_000)));
        self::assertTrue($filter->accepts($this->file('/in/big.wav', size: 200_000)));
    }

    public function test_minimum_age_skips_files_still_being_written(): void
    {
        $filter = new FileFilter(['min_age_seconds' => 60]);
        $now = 10_000;
        self::assertFalse($filter->accepts($this->file('/in/fresh.wav', modified: $now - 10), $now));
        self::assertTrue($filter->accepts($this->file('/in/settled.wav', modified: $now - 120), $now));
    }

    private function file(string $path, int $size = 1_000_000, int $modified = 1000): RemoteFile
    {
        return new RemoteFile($path, $size, $modified);
    }
}
