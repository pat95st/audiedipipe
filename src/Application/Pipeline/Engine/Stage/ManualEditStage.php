<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine\Stage;

use AudiEdiPipe\Application\Pipeline\Engine\StageContext;
use AudiEdiPipe\Application\Pipeline\Engine\StageHandler;
use AudiEdiPipe\Application\Pipeline\Engine\StageOutcome;
use AudiEdiPipe\Application\Template\TemplateRenderer;
use AudiEdiPipe\Domain\Job\JobStatus;
use AudiEdiPipe\Infrastructure\Audio\Ffmpeg;

/**
 * Pauses the pipeline until a cutter has cut the audio and edited the
 * metadata in the browser. After submission the cuts are applied.
 */
final class ManualEditStage implements StageHandler
{
    public function __construct(private readonly Ffmpeg $ffmpeg, private readonly TemplateRenderer $templates)
    {
    }

    public function supports(string $type): bool
    {
        return $type === 'manual_edit';
    }

    public function run(StageContext $context): StageOutcome
    {
        $input = $context->requireInput();
        $submission = $context->params['submission'] ?? null;
        if (!is_array($submission)) {
            $templateId = (int) ($context->params['template_id'] ?? 0) ?: (int) ($context->job['metadata_template_id'] ?? 0);
            if ($templateId > 0) {
                $context->metadata = $this->templates->applyTemplate($templateId, $context->jobId(), $context->metadata);
                $context->log('Metadata template #' . $templateId . ' applied.');
            }
            $context->log('Waiting for manual cut and metadata.');

            return StageOutcome::wait(JobStatus::WaitingManual);
        }

        $cuts = [];
        foreach ((array) ($submission['cuts'] ?? []) as $cut) {
            if (is_array($cut) && isset($cut[0], $cut[1])) {
                $cuts[] = [(float) $cut[0], (float) $cut[1]];
            }
        }
        if ($cuts === []) {
            $context->log('No cuts - audio unchanged.');

            return StageOutcome::success();
        }
        $removed = array_sum(array_map(static fn (array $c): float => $c[1] - $c[0], $cuts));
        $context->log(sprintf('Removing %d region(s), %.2f s in total.', count($cuts), $removed));
        $output = $context->outputFile();
        $result = $this->ffmpeg->cut($input, $output, $cuts, $context->sampleRate);
        if (!$result->successful()) {
            return StageOutcome::failed('Cutting failed: ' . $result->tail(5));
        }

        return StageOutcome::success($output);
    }
}
