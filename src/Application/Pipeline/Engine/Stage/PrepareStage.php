<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine\Stage;

use AudiEdiPipe\Application\Pipeline\Engine\StageContext;
use AudiEdiPipe\Application\Pipeline\Engine\StageHandler;
use AudiEdiPipe\Application\Pipeline\Engine\StageOutcome;
use AudiEdiPipe\Infrastructure\Audio\Ffmpeg;

/**
 * Decodes the original file into the internal 24 bit WAV working format and
 * takes over existing tags as metadata defaults.
 */
final class PrepareStage implements StageHandler
{
    public function __construct(private readonly Ffmpeg $ffmpeg)
    {
    }

    public function supports(string $type): bool
    {
        return $type === 'prepare';
    }

    public function run(StageContext $context): StageOutcome
    {
        $input = (string) $context->job['input_path'];
        if (!is_file($input)) {
            return StageOutcome::failed('Input file not found: ' . basename($input));
        }
        $probe = $this->ffmpeg->probe($input);
        $context->log(sprintf(
            'Input: %s, %s, %d Hz, %d ch, %.1f s',
            basename((string) $context->job['original_name']),
            $probe['codec'],
            $probe['sample_rate'],
            $probe['channels'],
            $probe['duration'],
        ));
        foreach (['title', 'artist', 'album', 'genre', 'date', 'comment'] as $tag) {
            if (($probe['tags'][$tag] ?? '') !== '' && ($context->metadata[$tag] ?? '') === '') {
                $context->metadata[$tag] = $probe['tags'][$tag];
            }
        }
        $context->metadata['_duration'] = $probe['duration'];
        $context->metadata['_sample_rate'] = $probe['sample_rate'];
        $context->metadata['_channels'] = $probe['channels'];

        $output = $context->outputFile();
        $result = $this->ffmpeg->toWorkingFormat($input, $output);
        if (!$result->successful()) {
            return StageOutcome::failed('Decoding failed: ' . $result->tail(5));
        }

        return StageOutcome::success($output);
    }
}
