<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Dynamic equalizer with up to four bands.
 *
 * Each band splits the signal into a band component (band-pass, low-pass or
 * high-pass) and its exact complement (input minus band). Only the band
 * component is compressed (sidechained by itself) and summed back. With no
 * gain reduction the output is bit-identical in level to the input, which is
 * more predictable than the ffmpeg adynamicequalizer filter whose threshold
 * semantics differ between ffmpeg versions.
 */
final class DynamicEq extends AbstractEffect
{
    public const BANDS = 4;

    private const DEFAULT_BANDS = [
        ['enabled' => true, 'type' => 'bell', 'frequency' => 6500, 'q' => 3.0, 'threshold' => -30, 'ratio' => 4, 'attack' => 2, 'release' => 80],
        ['enabled' => false, 'type' => 'low_shelf', 'frequency' => 150, 'q' => 0.7, 'threshold' => -24, 'ratio' => 3, 'attack' => 20, 'release' => 200],
        ['enabled' => false, 'type' => 'bell', 'frequency' => 350, 'q' => 1.5, 'threshold' => -28, 'ratio' => 2.5, 'attack' => 15, 'release' => 150],
        ['enabled' => false, 'type' => 'bell', 'frequency' => 3000, 'q' => 2.0, 'threshold' => -30, 'ratio' => 3, 'attack' => 5, 'release' => 100],
    ];

    public function type(): string
    {
        return 'dynamic_eq';
    }

    public function schema(): array
    {
        return [
            'bands' => ['type' => 'bands', 'count' => self::BANDS, 'fields' => $this->bandSchema(), 'default' => self::DEFAULT_BANDS],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bandSchema(): array
    {
        return [
            'enabled' => ['type' => 'bool', 'default' => false],
            'type' => ['type' => 'select', 'options' => ['bell', 'low_shelf', 'high_shelf'], 'default' => 'bell'],
            'frequency' => ['type' => 'number', 'min' => 20, 'max' => 20000, 'step' => 1, 'default' => 1000, 'unit' => 'Hz'],
            'q' => ['type' => 'number', 'min' => 0.1, 'max' => 18, 'step' => 0.01, 'default' => 1, 'unit' => ''],
            'threshold' => ['type' => 'number', 'min' => -60, 'max' => 0, 'step' => 0.5, 'default' => -30, 'unit' => 'dB'],
            'ratio' => ['type' => 'number', 'min' => 1, 'max' => 20, 'step' => 0.1, 'default' => 3, 'unit' => ':1'],
            'attack' => ['type' => 'number', 'min' => 0.01, 'max' => 2000, 'step' => 0.1, 'default' => 5, 'unit' => 'ms'],
            'release' => ['type' => 'number', 'min' => 0.01, 'max' => 9000, 'step' => 1, 'default' => 100, 'unit' => 'ms'],
        ];
    }

    public function normalize(array $params): array
    {
        $bands = is_array($params['bands'] ?? null) ? array_values($params['bands']) : [];
        $result = ['bands' => []];
        for ($i = 0; $i < self::BANDS; ++$i) {
            $band = is_array($bands[$i] ?? null) ? $bands[$i] : [];
            $result['bands'][] = self::normalizeAgainst($this->bandSchema(), $band + self::DEFAULT_BANDS[$i]);
        }

        return $result;
    }

    public function filter(array $params): string
    {
        $chains = [];
        $bands = is_array($params['bands'] ?? null) ? $params['bands'] : [];
        $index = 0;
        foreach ($bands as $band) {
            if (!is_array($band) || ($band['enabled'] ?? false) !== true || self::f($band, 'ratio') <= 1.0) {
                continue;
            }
            $p = 'd' . $index++;
            $f = self::num(self::f($band, 'frequency'), 2);
            $q = self::num(self::f($band, 'q'), 3);
            $splitter = match ($band['type'] ?? 'bell') {
                'low_shelf' => sprintf('lowpass=f=%s:p=2:t=q:w=%s', $f, $q),
                'high_shelf' => sprintf('highpass=f=%s:p=2:t=q:w=%s', $f, $q),
                default => sprintf('bandpass=f=%s:t=q:w=%s', $f, $q),
            };
            $compressor = sprintf(
                'sidechaincompress=threshold=%s:ratio=%s:attack=%s:release=%s:knee=2',
                self::num(max(0.000976563, self::dbToLinear(self::f($band, 'threshold')))),
                self::num(self::f($band, 'ratio'), 2),
                self::num(self::f($band, 'attack'), 2),
                self::num(self::f($band, 'release'), 2),
            );
            $chains[] = sprintf(
                'asplit=2[%1$sA][%1$sB];[%1$sB]%2$s,asplit=3[%1$sM][%1$sS][%1$sN];'
                . '[%1$sM][%1$sS]%3$s[%1$sC];[%1$sN]volume=-1[%1$sI];'
                . '[%1$sA][%1$sI][%1$sC]amix=inputs=3:normalize=0',
                $p,
                $splitter,
                $compressor,
            );
        }

        return implode(',', $chains);
    }
}
