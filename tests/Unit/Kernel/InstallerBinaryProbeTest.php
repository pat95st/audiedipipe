<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Kernel;

use AudiEdiPipe\Kernel\Installer;
use AudiEdiPipe\Tests\Support\TestCase;

/**
 * The installer must accept a custom ffmpeg/ffprobe path and explain why a
 * binary cannot be used, so users on shared hosting can fix it themselves.
 */
final class InstallerBinaryProbeTest extends TestCase
{
    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Shell script fixtures need a POSIX system.');
        }
    }

    public function test_custom_binary_path_is_accepted(): void
    {
        $bin = $this->script($this->tempDir(), 'ffmpeg', 'echo "ffmpeg version 99.0-fake Copyright (c) test"');

        $probe = $this->installer()->probeBinary($bin);

        self::assertTrue($probe['ok']);
        self::assertSame('ffmpeg version 99.0-fake Copyright (c) test', $probe['detail']);
        self::assertSame('', $probe['hint']);
    }

    public function test_directory_instead_of_binary_is_explained(): void
    {
        $probe = $this->installer()->probeBinary($this->tempDir());

        self::assertFalse($probe['ok']);
        self::assertSame('install.hint.binary_is_directory', $probe['hint']);
    }

    public function test_missing_file_is_explained(): void
    {
        $probe = $this->installer()->probeBinary($this->tempDir() . '/does-not-exist/ffmpeg');

        self::assertFalse($probe['ok']);
        self::assertSame('install.hint.binary_not_found', $probe['hint']);
    }

    public function test_missing_execute_permission_is_explained(): void
    {
        $bin = $this->script($this->tempDir(), 'ffmpeg', 'echo "ffmpeg version 99.0"', false);

        $probe = $this->installer()->probeBinary($bin);

        self::assertFalse($probe['ok']);
        self::assertSame('install.hint.binary_not_executable', $probe['hint']);
    }

    public function test_unknown_command_name_suggests_absolute_path(): void
    {
        $probe = $this->installer()->probeBinary('aep-no-such-binary-' . bin2hex(random_bytes(4)));

        self::assertFalse($probe['ok']);
        self::assertSame('install.hint.binary_not_on_path', $probe['hint']);
    }

    public function test_failing_binary_reports_its_error_output(): void
    {
        $bin = $this->script($this->tempDir(), 'ffmpeg', 'echo "cannot execute: Exec format error" >&2; exit 126');

        $probe = $this->installer()->probeBinary($bin);

        self::assertFalse($probe['ok']);
        self::assertSame('install.hint.binary_failed', $probe['hint']);
        self::assertStringContainsString('Exec format error', $probe['detail']);
    }

    public function test_empty_path_and_disabled_process_functions(): void
    {
        self::assertSame('install.hint.binary_empty', $this->installer()->probeBinary('  ')['hint']);
        self::assertSame('install.hint.no_process', $this->installer()->probeBinary('ffmpeg', false)['hint']);
    }

    public function test_requirements_check_both_ffmpeg_and_ffprobe_with_given_paths(): void
    {
        $dir = $this->tempDir();
        $ffmpeg = $this->script($dir, 'ffmpeg', 'echo "ffmpeg version 99.0-fake"');
        $ffprobe = $this->script($dir, 'ffprobe', 'echo "ffprobe version 99.0-fake"');

        $checks = $this->installer()->requirements($ffmpeg, $ffprobe);
        $byKey = array_column($checks, null, 'key');

        self::assertTrue($byKey['ffmpeg']['ok']);
        self::assertTrue($byKey['ffmpeg']['required']);
        self::assertTrue($byKey['ffprobe']['ok']);
        self::assertSame('ffprobe version 99.0-fake', $byKey['ffprobe']['detail']);
        foreach ($checks as $check) {
            self::assertArrayHasKey('hint', $check);
        }
    }

    private function script(string $dir, string $name, string $body, bool $executable = true): string
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, $executable ? 0755 : 0644);

        return $path;
    }

    private function installer(): Installer
    {
        return new Installer($this->tempDir('aepinst'));
    }
}
