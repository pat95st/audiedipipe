<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine;

/**
 * Everything a stage handler needs to process one stage of one job.
 */
final class StageContext
{
    /** @var list<string> */
    private array $log = [];

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $stage
     * @param array<string, mixed> $params
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly array $job,
        public readonly array $stage,
        public readonly array $params,
        public readonly ?string $inputFile,
        public readonly string $workDirectory,
        public readonly int $sampleRate,
        public array $metadata,
    ) {
    }

    public function jobId(): int
    {
        return (int) $this->job['id'];
    }

    public function position(): int
    {
        return (int) $this->stage['position'];
    }

    public function outputFile(string $suffix = 'wav'): string
    {
        return sprintf('%s/%02d_%s.%s', $this->workDirectory, $this->position(), preg_replace('/[^a-z0-9_]/', '', (string) $this->stage['type']), $suffix);
    }

    public function log(string $line): void
    {
        $this->log[] = '[' . gmdate('H:i:s') . '] ' . $line;
    }

    public function logText(): string
    {
        return implode("\n", $this->log);
    }

    public function requireInput(): string
    {
        if ($this->inputFile === null || !is_file($this->inputFile)) {
            throw new \RuntimeException('Input file of the previous stage is missing.');
        }

        return $this->inputFile;
    }
}
