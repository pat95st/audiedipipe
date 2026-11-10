<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Broadband noise reduction based on FFT denoising (ffmpeg afftdn).
 */
final class NoiseReduction extends AbstractEffect
{
    public function type(): string
    {
        return 'noise_reduction';
    }

    public function schema(): array
    {
        return [
            'reduction' => ['type' => 'number', 'min' => 0.01, 'max' => 97, 'step' => 0.5, 'default' => 12, 'unit' => 'dB'],
            'noise_floor' => ['type' => 'number', 'min' => -80, 'max' => -20, 'step' => 1, 'default' => -50, 'unit' => 'dB'],
            'track_noise' => ['type' => 'bool', 'default' => true],
            'noise_type' => ['type' => 'select', 'options' => ['w', 'v', 's'], 'default' => 'w'],
        ];
    }

    public function filter(array $params): string
    {
        return sprintf(
            'afftdn=nr=%s:nf=%s:nt=%s:tn=%d',
            self::num(self::f($params, 'reduction'), 2),
            self::num(self::f($params, 'noise_floor'), 2),
            (string) ($params['noise_type'] ?? 'w'),
            ($params['track_noise'] ?? false) === true ? 1 : 0,
        );
    }
}
