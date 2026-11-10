<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * An audio effect translates validated parameters into an ffmpeg audio filter chain.
 */
interface AudioEffect
{
    /**
     * Unique machine name, also used as pipeline stage type.
     */
    public function type(): string;

    /**
     * Parameter schema (exported to the UI and used for validation).
     *
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array;

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * Clamp and sanitize user supplied parameters.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function normalize(array $params): array;

    /**
     * ffmpeg -af filter chain for the given (normalized) parameters. Empty string = no-op.
     *
     * @param array<string, mixed> $params
     */
    public function filter(array $params): string;
}
