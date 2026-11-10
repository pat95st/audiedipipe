<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Audio;

use AudiEdiPipe\Infrastructure\Process\ProcessResult;
use AudiEdiPipe\Infrastructure\Process\ProcessRunner;
use RuntimeException;

/**
 * Wrapper around the ffmpeg / ffprobe binaries.
 */
class Ffmpeg
{
    public const OUTPUT_FORMATS = [
        'mp3' => ['codec' => 'libmp3lame', 'ext' => 'mp3', 'mime' => 'audio/mpeg', 'bitrate' => true],
        'aac' => ['codec' => 'aac', 'ext' => 'm4a', 'mime' => 'audio/mp4', 'bitrate' => true],
        'opus' => ['codec' => 'libopus', 'ext' => 'opus', 'mime' => 'audio/ogg', 'bitrate' => true],
        'ogg' => ['codec' => 'libvorbis', 'ext' => 'ogg', 'mime' => 'audio/ogg', 'bitrate' => true],
        'flac' => ['codec' => 'flac', 'ext' => 'flac', 'mime' => 'audio/flac', 'bitrate' => false],
        'wav' => ['codec' => 'pcm_s24le', 'ext' => 'wav', 'mime' => 'audio/wav', 'bitrate' => false],
    ];

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly string $ffprobe = 'ffprobe',
        private readonly int $timeout = 7200,
    ) {
    }

    public function version(): ?string
    {
        $result = $this->runner->run([$this->ffmpeg, '-hide_banner', '-version'], 15);
        if (!$result->successful()) {
            return null;
        }
        $first = strtok($result->stdout, "\n");

        return $first === false ? null : trim($first);
    }

    /**
     * @return array{duration: float, sample_rate: int, channels: int, codec: string, bit_rate: int, format: string, tags: array<string, string>}
     */
    public function probe(string $file): array
    {
        $result = $this->runner->run([
            $this->ffprobe, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', '-select_streams', 'a:0', $file,
        ], 60);
        if (!$result->successful()) {
            throw new RuntimeException('ffprobe failed: ' . $result->tail(5));
        }
        $data = json_decode($result->stdout, true);
        $stream = is_array($data) && isset($data['streams'][0]) && is_array($data['streams'][0]) ? $data['streams'][0] : [];
        $format = is_array($data) && isset($data['format']) && is_array($data['format']) ? $data['format'] : [];
        if ($stream === []) {
            throw new RuntimeException('No audio stream found in ' . basename($file));
        }
        $tags = [];
        foreach ((array) ($format['tags'] ?? []) as $key => $value) {
            $tags[strtolower((string) $key)] = is_scalar($value) ? (string) $value : '';
        }

        return [
            'duration' => (float) ($format['duration'] ?? $stream['duration'] ?? 0),
            'sample_rate' => (int) ($stream['sample_rate'] ?? 48000),
            'channels' => (int) ($stream['channels'] ?? 2),
            'codec' => (string) ($stream['codec_name'] ?? ''),
            'bit_rate' => (int) ($format['bit_rate'] ?? 0),
            'format' => (string) ($format['format_name'] ?? ''),
            'tags' => $tags,
        ];
    }

    /**
     * Decode any input into the internal working format (24 bit WAV).
     */
    public function toWorkingFormat(string $input, string $output, ?int $sampleRate = null): ProcessResult
    {
        $command = [$this->ffmpeg, '-hide_banner', '-nostdin', '-y', '-i', $input, '-vn', '-map', '0:a:0'];
        if ($sampleRate !== null) {
            $command = [...$command, '-ar', (string) $sampleRate];
        }

        return $this->runner->run([...$command, '-c:a', 'pcm_s24le', $output], $this->timeout);
    }

    /**
     * Apply an audio filter chain and write a 24 bit WAV with the original sample rate.
     */
    public function applyFilter(string $input, string $output, string $filter, int $sampleRate): ProcessResult
    {
        $command = [$this->ffmpeg, '-hide_banner', '-nostdin', '-y', '-i', $input];
        if ($filter !== '') {
            $command = [...$command, '-af', $filter];
        }

        return $this->runner->run(
            [...$command, '-ar', (string) $sampleRate, '-c:a', 'pcm_s24le', $output],
            $this->timeout,
        );
    }

    /**
     * Run a filter without output and return ffmpeg's log (analysis passes).
     */
    public function analyze(string $input, string $filter): ProcessResult
    {
        return $this->runner->run(
            [$this->ffmpeg, '-hide_banner', '-nostdin', '-nostats', '-i', $input, '-af', $filter, '-f', 'null', '-'],
            $this->timeout,
        );
    }

    /**
     * Encode to a distribution format including metadata tags and optional cover image.
     *
     * @param array<string, string> $metadata
     */
    public function encode(
        string $input,
        string $output,
        string $format,
        int $bitrateKbps,
        ?int $sampleRate,
        ?int $channels,
        array $metadata = [],
        ?string $coverImage = null,
    ): ProcessResult {
        $spec = self::OUTPUT_FORMATS[$format] ?? self::OUTPUT_FORMATS['mp3'];
        $command = [$this->ffmpeg, '-hide_banner', '-nostdin', '-y', '-i', $input];
        $hasCover = $coverImage !== null && is_file($coverImage) && in_array($format, ['mp3', 'aac', 'flac'], true);
        if ($hasCover) {
            $command = [...$command, '-i', $coverImage, '-map', '0:a', '-map', '1:v', '-c:v', $format === 'mp3' ? 'mjpeg' : 'copy', '-disposition:v', 'attached_pic'];
        } else {
            $command = [...$command, '-map', '0:a'];
        }
        $command = [...$command, '-c:a', $spec['codec']];
        if ($spec['bitrate']) {
            $command = [...$command, '-b:a', max(32, $bitrateKbps) . 'k'];
        }
        if ($sampleRate !== null && $sampleRate > 0) {
            $command = [...$command, '-ar', (string) ($format === 'opus' ? 48000 : $sampleRate)];
        }
        if ($channels !== null && $channels > 0) {
            $command = [...$command, '-ac', (string) $channels];
        }
        if ($format === 'mp3') {
            $command = [...$command, '-id3v2_version', '3', '-write_id3v1', '1'];
        }
        foreach ($metadata as $key => $value) {
            if ($value !== '') {
                $command = [...$command, '-metadata', $key . '=' . $value];
            }
        }

        return $this->runner->run([...$command, $output], $this->timeout);
    }

    /**
     * Remove time ranges (seconds) from a file - used by the manual cut stage.
     *
     * @param list<array{0: float, 1: float}> $cuts
     */
    public function cut(string $input, string $output, array $cuts, int $sampleRate): ProcessResult
    {
        $filter = self::cutFilter($cuts);

        return $this->applyFilter($input, $output, $filter, $sampleRate);
    }

    /**
     * @param list<array{0: float, 1: float}> $cuts
     */
    public static function cutFilter(array $cuts): string
    {
        $parts = [];
        foreach ($cuts as [$start, $end]) {
            if ($end > $start) {
                $parts[] = sprintf('between(t,%s,%s)', self::fmt($start), self::fmt($end));
            }
        }
        if ($parts === []) {
            return '';
        }

        // aselect drops the samples, asetpts closes the gaps.
        return sprintf("aselect='not(%s)',asetpts=N/SR/TB", implode('+', $parts));
    }

    /**
     * Downsampled peak data for waveform display: list of [min, max] pairs in -1..1.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function peaks(string $input, int $buckets = 2000, int $rate = 2000): array
    {
        $result = $this->runner->run([
            $this->ffmpeg, '-hide_banner', '-nostdin', '-v', 'error', '-i', $input,
            '-ac', '1', '-ar', (string) $rate, '-f', 's16le', '-acodec', 'pcm_s16le', '-',
        ], $this->timeout);
        if (!$result->successful()) {
            throw new RuntimeException('Unable to compute waveform: ' . $result->tail(3));
        }

        return self::peaksFromPcm($result->stdout, $buckets);
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public static function peaksFromPcm(string $pcm, int $buckets): array
    {
        $samples = intdiv(strlen($pcm), 2);
        if ($samples === 0) {
            return [];
        }
        $buckets = max(1, min($buckets, $samples));
        $perBucket = $samples / $buckets;

        /** @var array<int, int> $values */
        $values = unpack('s*', substr($pcm, 0, $samples * 2)) ?: [];
        $peaks = [];
        for ($b = 0; $b < $buckets; ++$b) {
            $from = (int) floor($b * $perBucket) + 1;
            $to = (int) min($samples, floor(($b + 1) * $perBucket));
            $min = 0;
            $max = 0;
            for ($i = $from; $i <= $to; ++$i) {
                $v = $values[$i];
                if ($v < $min) {
                    $min = $v;
                } elseif ($v > $max) {
                    $max = $v;
                }
            }
            $peaks[] = [round($min / 32768, 4), round($max / 32767, 4)];
        }

        return $peaks;
    }

    private static function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
