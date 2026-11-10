<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine\Stage;

use AudiEdiPipe\Application\Pipeline\Engine\StageContext;
use AudiEdiPipe\Application\Pipeline\Engine\StageHandler;
use AudiEdiPipe\Application\Pipeline\Engine\StageOutcome;
use AudiEdiPipe\Domain\Audio\Effect\EffectRegistry;
use AudiEdiPipe\Domain\Audio\Effect\TwoPassEffect;
use AudiEdiPipe\Infrastructure\Audio\Ffmpeg;

final class EffectStage implements StageHandler
{
    public function __construct(private readonly EffectRegistry $effects, private readonly Ffmpeg $ffmpeg)
    {
    }

    public function supports(string $type): bool
    {
        return $this->effects->has($type);
    }

    public function run(StageContext $context): StageOutcome
    {
        $input = $context->requireInput();
        $effect = $this->effects->get((string) $context->stage['type']);
        $params = $effect->normalize($context->params);

        if ($effect instanceof TwoPassEffect && ($params['two_pass'] ?? true) === true) {
            $analysisFilter = $effect->analysisFilter($params);
            $context->log('Analysis: ' . $analysisFilter);
            $analysis = $this->ffmpeg->analyze($input, $analysisFilter);
            if (!$analysis->successful()) {
                return StageOutcome::failed('Analysis failed: ' . $analysis->tail(5));
            }
            $measured = $effect->parseAnalysis($analysis->stderr);
            if ($measured !== []) {
                $context->log(sprintf(
                    'Measured: %.1f LUFS, %.1f dBTP, LRA %.1f LU',
                    (float) $measured['input_i'],
                    (float) $measured['input_tp'],
                    (float) $measured['input_lra'],
                ));
                $context->metadata['_loudness_in'] = $measured['input_i'];
            }
            $filter = $effect->filterWithAnalysis($params, $measured);
        } else {
            $filter = $effect->filter($params);
        }

        if ($filter === '') {
            return StageOutcome::skipped('No active settings - stage skipped.');
        }
        $context->log('Filter: ' . $filter);
        $output = $context->outputFile();
        $result = $this->ffmpeg->applyFilter($input, $output, $filter, $context->sampleRate);
        if (!$result->successful()) {
            return StageOutcome::failed('ffmpeg failed: ' . $result->tail(8));
        }

        return StageOutcome::success($output);
    }
}
