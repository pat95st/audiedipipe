<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Feed-forward compressor (ffmpeg acompressor).
 */
final class Compressor extends AbstractEffect
{
    public function type(): string
    {
        return 'compressor';
    }

    public function schema(): array
    {
        return [
            'threshold' => ['type' => 'number', 'min' => -60, 'max' => 0, 'step' => 0.5, 'default' => -18, 'unit' => 'dB'],
            'ratio' => ['type' => 'number', 'min' => 1, 'max' => 20, 'step' => 0.1, 'default' => 3, 'unit' => ':1'],
            'attack' => ['type' => 'number', 'min' => 0.01, 'max' => 2000, 'step' => 0.1, 'default' => 10, 'unit' => 'ms'],
            'release' => ['type' => 'number', 'min' => 0.01, 'max' => 9000, 'step' => 1, 'default' => 150, 'unit' => 'ms'],
            'makeup' => ['type' => 'number', 'min' => 0, 'max' => 24, 'step' => 0.1, 'default' => 3, 'unit' => 'dB'],
            'knee' => ['type' => 'number', 'min' => 1, 'max' => 8, 'step' => 0.1, 'default' => 2.8, 'unit' => 'dB'],
            'detection' => ['type' => 'select', 'options' => ['rms', 'peak'], 'default' => 'rms'],
            'link' => ['type' => 'select', 'options' => ['average', 'maximum'], 'default' => 'average'],
            'mix' => ['type' => 'number', 'min' => 0, 'max' => 100, 'step' => 1, 'default' => 100, 'unit' => '%'],
        ];
    }

    public function filter(array $params): string
    {
        return sprintf(
            'acompressor=threshold=%s:ratio=%s:attack=%s:release=%s:makeup=%s:knee=%s:detection=%s:link=%s:mix=%s',
            self::num(max(0.000976563, self::dbToLinear(self::f($params, 'threshold')))),
            self::num(self::f($params, 'ratio'), 2),
            self::num(self::f($params, 'attack'), 2),
            self::num(self::f($params, 'release'), 2),
            self::num(min(64, self::dbToLinear(self::f($params, 'makeup'))), 4),
            self::num(self::f($params, 'knee'), 2),
            (string) ($params['detection'] ?? 'rms'),
            (string) ($params['link'] ?? 'average'),
            self::num(self::f($params, 'mix') / 100, 3),
        );
    }
}
