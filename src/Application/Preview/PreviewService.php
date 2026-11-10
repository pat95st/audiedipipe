<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Preview;

use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Domain\Audio\Effect\EffectRegistry;
use AudiEdiPipe\Domain\Audio\Effect\TwoPassEffect;
use AudiEdiPipe\Infrastructure\Audio\Ffmpeg;
use RuntimeException;

/**
 * Renders short previews of single effects or effect chains so the effect
 * GUIs can be tried out with an own test file (A/B comparison).
 *
 * Files live in storage/preview/<token>/ and are removed by the worker after a day.
 */
final class PreviewService
{
    public const MAX_SECONDS = 120;

    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly EffectRegistry $effects,
        private readonly string $directory,
    ) {
    }

    /**
     * Import an uploaded file: decode, cut to MAX_SECONDS and create a playable MP3.
     *
     * @return array{token: string, duration: float}
     */
    public function import(string $uploadedFile, float $offset = 0.0): array
    {
        $token = bin2hex(random_bytes(12));
        $dir = $this->dir($token);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create preview directory.');
        }
        $probe = $this->ffmpeg->probe($uploadedFile);
        $rate = (int) ($probe['sample_rate'] ?? 48000) ?: 48000;
        $filter = sprintf('atrim=start=%s:duration=%d,asetpts=PTS-STARTPTS', number_format(max(0.0, $offset), 3, '.', ''), self::MAX_SECONDS);
        $result = $this->ffmpeg->applyFilter($uploadedFile, $dir . '/source.wav', $filter, $rate);
        if (!$result->successful()) {
            throw new ValidationException('upload.error.not_audio');
        }
        $this->playable($dir . '/source.wav', $dir . '/source.mp3');
        $duration = min(self::MAX_SECONDS, max(0.0, (float) ($probe['duration'] ?? 0) - $offset));

        return ['token' => $token, 'duration' => $duration];
    }

    /**
     * Render a chain of effect stages (list of {type, params, enabled}) and return the MP3 file name.
     *
     * @param list<array<string, mixed>> $chain
     *
     * @return array{file: string, log: list<string>}
     */
    public function render(string $token, array $chain): array
    {
        $dir = $this->dir($token);
        $source = $dir . '/source.wav';
        if (!is_file($source)) {
            throw new ValidationException('preview.error.expired');
        }
        $normalized = [];
        foreach ($chain as $stage) {
            $type = (string) ($stage['type'] ?? '');
            if (!$this->effects->has($type) || ($stage['enabled'] ?? true) === false) {
                continue;
            }
            $params = is_array($stage['params'] ?? null) ? $stage['params'] : [];
            $normalized[] = ['type' => $type, 'params' => $this->effects->get($type)->normalize($params)];
        }
        $hash = substr(hash('sha256', (string) json_encode($normalized)), 0, 16);
        $mp3 = 'render_' . $hash . '.mp3';
        if (is_file($dir . '/' . $mp3)) {
            return ['file' => $mp3, 'log' => ['cached']];
        }

        $rate = (int) ($this->ffmpeg->probe($source)['sample_rate'] ?? 48000) ?: 48000;
        $log = [];
        $current = $source;
        foreach ($normalized as $index => $stage) {
            $effect = $this->effects->get($stage['type']);
            $params = $stage['params'];
            if ($effect instanceof TwoPassEffect && ($params['two_pass'] ?? true) === true) {
                $analysis = $this->ffmpeg->analyze($current, $effect->analysisFilter($params));
                $filter = $effect->filterWithAnalysis($params, $analysis->successful() ? $effect->parseAnalysis($analysis->stderr) : []);
            } else {
                $filter = $effect->filter($params);
            }
            if ($filter === '') {
                continue;
            }
            $log[] = $stage['type'] . ': ' . $filter;
            $next = $dir . '/step_' . $hash . '_' . $index . '.wav';
            $result = $this->ffmpeg->applyFilter($current, $next, $filter, $rate);
            if (!$result->successful()) {
                throw new RuntimeException('ffmpeg: ' . $result->tail(4));
            }
            if ($current !== $source) {
                @unlink($current);
            }
            $current = $next;
        }
        $this->playable($current, $dir . '/' . $mp3);
        if ($current !== $source) {
            @unlink($current);
        }

        return ['file' => $mp3, 'log' => $log];
    }

    /**
     * Absolute path of a preview file or null when it does not exist.
     */
    public function path(string $token, string $file): ?string
    {
        if (preg_match('/^[a-f0-9]{24}$/', $token) !== 1 || preg_match('/^(source|render_[a-f0-9]{16})\.mp3$/', $file) !== 1) {
            return null;
        }
        $path = $this->dir($token) . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * Delete previews older than the given age.
     */
    public function cleanup(int $maxAgeSeconds = 86400): int
    {
        $removed = 0;
        foreach (glob($this->directory . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime !== false && $mtime < time() - $maxAgeSeconds) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
                ++$removed;
            }
        }

        return $removed;
    }

    private function playable(string $wav, string $mp3): void
    {
        $result = $this->ffmpeg->encode($wav, $mp3, 'mp3', 160, null, null);
        if (!$result->successful()) {
            throw new RuntimeException('ffmpeg: ' . $result->tail(4));
        }
    }

    private function dir(string $token): string
    {
        if (preg_match('/^[a-f0-9]{24}$/', $token) !== 1) {
            throw new ValidationException('preview.error.expired');
        }

        return rtrim($this->directory, '/') . '/' . $token;
    }
}
