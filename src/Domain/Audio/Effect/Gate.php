<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Noise gate (ffmpeg agate with a high ratio).
 */
final class Gate extends AbstractEffect
{
    public function type(): string
    {
        return 'gate';
    }

    public function schema(): array
    {
        return [
            'threshold' => ['type' => 'number', 'min' => -80, 'max' => 0, 'step' => 0.5, 'default' => -45, 'unit' => 'dB'],
            'range' => ['type' => 'number', 'min' => -90, 'max' => 0, 'step' => 1, 'default' => -40, 'unit' => 'dB'],
            'ratio' => ['type' => 'number', 'min' => 1, 'max' => 100, 'step' => 1, 'default' => 20, 'unit' => ':1'],
            'attack' => ['type' => 'number', 'min' => 0.01, 'max' => 9000, 'step' => 0.1, 'default' => 5, 'unit' => 'ms'],
            'release' => ['type' => 'number', 'min' => 0.01, 'max' => 9000, 'step' => 1, 'default' => 150, 'unit' => 'ms'],
            'knee' => ['type' => 'number', 'min' => 1, 'max' => 8, 'step' => 0.1, 'default' => 2.8, 'unit' => 'dB'],
            'detection' => ['type' => 'select', 'options' => ['rms', 'peak'], 'default' => 'rms'],
        ];
    }

    public function filter(array $params): string
    {
        return self::agate($params, 'downward');
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function agate(array $params, string $mode): string
    {
        return sprintf(
            'agate=mode=%s:threshold=%s:range=%s:ratio=%s:attack=%s:release=%s:knee=%s:detection=%s',
            $mode,
            self::num(max(0.0, min(1.0, self::dbToLinear(self::f($params, 'threshold'))))),
            self::num(max(0.0, min(1.0, self::dbToLinear(self::f($params, 'range'))))),
            self::num(self::f($params, 'ratio'), 2),
            self::num(self::f($params, 'attack'), 2),
            self::num(self::f($params, 'release'), 2),
            self::num(self::f($params, 'knee'), 2),
            (string) ($params['detection'] ?? 'rms'),
        );
    }
}
