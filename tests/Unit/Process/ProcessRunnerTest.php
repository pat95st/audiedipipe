<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Process;

use AudiEdiPipe\Infrastructure\Process\ProcessResult;
use AudiEdiPipe\Infrastructure\Process\ProcessRunner;
use AudiEdiPipe\Tests\Support\TestCase;

final class ProcessRunnerTest extends TestCase
{
    private const COMMAND = ['/bin/sh', '-c', 'echo out; echo err >&2; exit 3'];

    protected function setUp(): void
    {
        if (!is_executable('/bin/sh')) {
            self::markTestSkipped('Needs /bin/sh.');
        }
    }

    public function test_proc_open_keeps_stdout_and_stderr_apart(): void
    {
        $result = (new ProcessRunner())->run(self::COMMAND, 10);

        self::assertSame(3, $result->exitCode);
        self::assertSame('out', trim($result->stdout));
        self::assertSame('err', trim($result->stderr));
    }

    /**
     * Hosts without proc_open fall back to exec(); stdout must still be
     * parseable on its own (ffprobe JSON, ffmpeg version line).
     */
    public function test_exec_fallback_keeps_stdout_and_stderr_apart(): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec() is disabled.');
        }
        $method = new \ReflectionMethod(ProcessRunner::class, 'runWithExec');
        $result = $method->invoke(new ProcessRunner(), self::COMMAND);

        self::assertInstanceOf(ProcessResult::class, $result);
        self::assertSame(3, $result->exitCode);
        self::assertSame('out', trim($result->stdout));
        self::assertSame('err', trim($result->stderr));
    }
}
