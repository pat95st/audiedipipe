<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Effects that need an analysis pass first (e.g. EBU R128 loudness normalization).
 */
interface TwoPassEffect extends AudioEffect
{
    /**
     * @param array<string, mixed> $params
     */
    public function analysisFilter(array $params): string;

    /**
     * Parse the analysis output (ffmpeg stderr).
     *
     * @return array<string, mixed>
     */
    public function parseAnalysis(string $output): array;

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $analysis
     */
    public function filterWithAnalysis(array $params, array $analysis): string;
}
