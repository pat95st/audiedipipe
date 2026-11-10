<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine;

use AudiEdiPipe\Domain\Job\JobStatus;

final class StageOutcome
{
    private function __construct(
        public readonly string $result,
        public readonly ?string $outputFile = null,
        public readonly ?JobStatus $waitStatus = null,
        public readonly string $message = '',
        public readonly ?string $finalOutput = null,
    ) {
    }

    /**
     * @param string|null $outputFile  new working file for following stages (null = unchanged)
     * @param string|null $finalOutput distribution file (export stage)
     */
    public static function success(?string $outputFile = null, ?string $finalOutput = null, string $message = ''): self
    {
        return new self('success', $outputFile, null, $message, $finalOutput);
    }

    public static function wait(JobStatus $status, string $message = ''): self
    {
        return new self('waiting', null, $status, $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', null, null, $message);
    }

    public static function skipped(string $message = ''): self
    {
        return new self('skipped', null, null, $message);
    }
}
