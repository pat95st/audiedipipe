<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Process;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut = false,
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    public function tail(int $lines = 20): string
    {
        $all = preg_split('/\R/', trim($this->stderr . "\n" . $this->stdout)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
