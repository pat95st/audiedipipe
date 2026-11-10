<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Six band parametric equalizer (bell, shelves, pass filters, notch).
 */
final class ParametricEq extends AbstractEffect
{
    public const BANDS = 6;

    private const BAND_TYPES = ['bell', 'low_shelf', 'high_shelf', 'low_pass', 'high_pass', 'notch'];

    private const DEFAULT_BANDS = [
        ['type' => 'low_shelf', 'frequency' => 80, 'gain' => 0, 'q' => 0.7],
        ['type' => 'bell', 'frequency' => 250, 'gain' => 0, 'q' => 1.0],
        ['type' => 'bell', 'frequency' => 1000, 'gain' => 0, 'q' => 1.0],
        ['type' => 'bell', 'frequency' => 3000, 'gain' => 0, 'q' => 1.0],
        ['type' => 'bell', 'frequency' => 6000, 'gain' => 0, 'q' => 1.0],
        ['type' => 'high_shelf', 'frequency' => 12000, 'gain' => 0, 'q' => 0.7],
    ];

    public function type(): string
    {
        return 'parametric_eq';
    }

    public function schema(): array
    {
        return [
            'output_gain' => ['type' => 'number', 'min' => -12, 'max' => 12, 'step' => 0.1, 'default' => 0, 'unit' => 'dB'],
            'bands' => ['type' => 'bands', 'count' => self::BANDS, 'fields' => $this->bandSchema(), 'default' => $this->defaultBands()],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bandSchema(): array
    {
        return [
            'enabled' => ['type' => 'bool', 'default' => true],
            'type' => ['type' => 'select', 'options' => self::BAND_TYPES, 'default' => 'bell'],
            'frequency' => ['type' => 'number', 'min' => 20, 'max' => 20000, 'step' => 1, 'default' => 1000, 'unit' => 'Hz'],
            'gain' => ['type' => 'number', 'min' => -18, 'max' => 18, 'step' => 0.1, 'default' => 0, 'unit' => 'dB'],
            'q' => ['type' => 'number', 'min' => 0.1, 'max' => 18, 'step' => 0.01, 'default' => 1, 'unit' => ''],
        ];
    }

    public function normalize(array $params): array
    {
        $result = [
            'output_gain' => self::clamp(is_numeric($params['output_gain'] ?? null) ? (float) $params['output_gain'] : 0.0, -12, 12),
            'bands' => [],
        ];
        $bands = is_array($params['bands'] ?? null) ? array_values($params['bands']) : [];
        $defaults = $this->defaultBands();
        for ($i = 0; $i < self::BANDS; ++$i) {
            $band = is_array($bands[$i] ?? null) ? $bands[$i] : [];
            $result['bands'][] = self::normalizeAgainst($this->bandSchema(), $band + $defaults[$i]);
        }

        return $result;
    }

    public function filter(array $params): string
    {
        $filters = [];
        $bands = is_array($params['bands'] ?? null) ? $params['bands'] : [];
        foreach ($bands as $band) {
            if (!is_array($band) || ($band['enabled'] ?? false) !== true) {
                continue;
            }
            $f = self::num(self::f($band, 'frequency'), 2);
            $g = self::num(self::f($band, 'gain'), 2);
            $q = self::num(self::f($band, 'q'), 3);
            $gainIsZero = abs(self::f($band, 'gain')) < 0.05;
            $filter = match ($band['type'] ?? 'bell') {
                'bell' => $gainIsZero ? null : sprintf('equalizer=f=%s:t=q:w=%s:g=%s', $f, $q, $g),
                'low_shelf' => $gainIsZero ? null : sprintf('lowshelf=f=%s:t=q:w=%s:g=%s', $f, $q, $g),
                'high_shelf' => $gainIsZero ? null : sprintf('highshelf=f=%s:t=q:w=%s:g=%s', $f, $q, $g),
                'low_pass' => sprintf('lowpass=f=%s:p=2:t=q:w=%s', $f, $q),
                'high_pass' => sprintf('highpass=f=%s:p=2:t=q:w=%s', $f, $q),
                'notch' => sprintf('bandreject=f=%s:t=q:w=%s', $f, $q),
                default => null,
            };
            if ($filter !== null) {
                $filters[] = $filter;
            }
        }
        $outputGain = self::f($params, 'output_gain');
        if (abs($outputGain) >= 0.05) {
            $filters[] = sprintf('volume=%sdB', self::num($outputGain, 2));
        }

        return implode(',', $filters);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultBands(): array
    {
        return array_values(array_map(static fn (array $b): array => ['enabled' => true] + $b, self::DEFAULT_BANDS));
    }
}
