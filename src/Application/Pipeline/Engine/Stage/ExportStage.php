<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine\Stage;

use AudiEdiPipe\Application\Pipeline\Engine\StageContext;
use AudiEdiPipe\Application\Pipeline\Engine\StageHandler;
use AudiEdiPipe\Application\Pipeline\Engine\StageOutcome;
use AudiEdiPipe\Application\Template\TemplateRenderer;
use AudiEdiPipe\Infrastructure\Audio\Ffmpeg;
use AudiEdiPipe\Infrastructure\Storage\StorageConnections;

/**
 * Encodes the result into a distribution format with ID3/metadata tags and
 * optionally uploads it to a storage connection.
 */
final class ExportStage implements StageHandler
{
    private const TAG_MAP = [
        'title' => 'title',
        'artist' => 'artist',
        'album' => 'album',
        'album_artist' => 'album_artist',
        'genre' => 'genre',
        'date' => 'date',
        'track' => 'track',
        'comment' => 'comment',
        'description' => 'description',
        'copyright' => 'copyright',
        'language' => 'language',
    ];

    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly TemplateRenderer $templates,
        private readonly StorageConnections $storage,
        private readonly string $outputDirectory,
        private readonly string $publicDirectory,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'export';
    }

    public function run(StageContext $context): StageOutcome
    {
        $input = $context->requireInput();
        $format = (string) ($context->params['format'] ?? 'mp3');
        $spec = Ffmpeg::OUTPUT_FORMATS[$format] ?? Ffmpeg::OUTPUT_FORMATS['mp3'];
        $vars = $this->templates->variables($context->jobId(), $context->metadata);
        $baseName = TemplateRenderer::render((string) ($context->params['filename'] ?? '{date}_{basename}'), $vars);
        $baseName = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $baseName), ' ._') ?: 'job_' . $context->jobId();
        $dir = $this->outputDirectory . '/job_' . $context->jobId();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return StageOutcome::failed('Cannot create output directory.');
        }
        $output = $dir . '/' . $baseName . '.' . $spec['ext'];

        $tags = [];
        foreach (self::TAG_MAP as $field => $tag) {
            $value = $context->metadata[$field] ?? '';
            if (is_scalar($value) && (string) $value !== '') {
                $tags[$tag] = (string) $value;
            }
        }
        $cover = null;
        if (($context->params['embed_cover'] ?? true) === true) {
            $cover = $this->resolveCover((string) ($context->metadata['cover'] ?? ''));
        }
        $sampleRate = (int) ($context->params['sample_rate'] ?? 0);
        $channels = (int) ($context->params['channels'] ?? 0);
        $result = $this->ffmpeg->encode(
            $input,
            $output,
            $format,
            (int) ($context->params['bitrate'] ?? 128),
            $sampleRate > 0 ? $sampleRate : null,
            $channels > 0 ? $channels : null,
            $tags,
            $cover,
        );
        if (!$result->successful()) {
            return StageOutcome::failed('Encoding failed: ' . $result->tail(8));
        }
        $context->log(sprintf('Exported %s (%s, %.1f MB).', basename($output), strtoupper($format), filesize($output) / 1048576));
        $context->metadata['_export_mime'] = $spec['mime'];

        $destinationId = (int) ($context->params['destination_id'] ?? 0);
        if ($destinationId > 0) {
            $adapter = $this->storage->adapter($destinationId);
            if ($adapter === null) {
                return StageOutcome::failed('Destination storage connection not found.');
            }
            $remote = rtrim(TemplateRenderer::render((string) ($context->params['destination_path'] ?? ''), $vars), '/') . '/' . basename($output);
            $adapter->upload($output, $remote);
            $context->log('Uploaded to ' . $remote);
        }

        return StageOutcome::success(null, $output);
    }

    private function resolveCover(string $cover): ?string
    {
        if ($cover === '') {
            return null;
        }
        // Covers are uploaded into public/uploads/covers and referenced relative to public/.
        $path = realpath($this->publicDirectory . '/' . ltrim($cover, '/'));
        $base = realpath($this->publicDirectory);
        if ($path === false || $base === false || !str_starts_with($path, $base) || !is_file($path)) {
            return null;
        }

        return $path;
    }
}
