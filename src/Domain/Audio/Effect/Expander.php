<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Downward expander: gently lowers signals below the threshold (low ratio, soft knee).
 */
final class Expander extends AbstractEffect
{
    public function type(): string
    {
        return 'expander';
    }

    public function schema(): array
    {
        return [
            'threshold' => ['type' => 'number', 'min' => -70, 'max' => 0, 'step' => 0.5, 'default' => -38, 'unit' => 'dB'],
            'ratio' => ['type' => 'number', 'min' => 1, 'max' => 10, 'step' => 0.1, 'default' => 2, 'unit' => ':1'],
            'range' => ['type' => 'number', 'min' => -60, 'max' => 0, 'step' => 1, 'default' => -18, 'unit' => 'dB'],
            'attack' => ['type' => 'number', 'min' => 0.01, 'max' => 9000, 'step' => 0.1, 'default' => 10, 'unit' => 'ms'],
            'release' => ['type' => 'number', 'min' => 0.01, 'max' => 9000, 'step' => 1, 'default' => 250, 'unit' => 'ms'],
            'knee' => ['type' => 'number', 'min' => 1, 'max' => 8, 'step' => 0.1, 'default' => 6, 'unit' => 'dB'],
            'detection' => ['type' => 'select', 'options' => ['rms', 'peak'], 'default' => 'rms'],
        ];
    }

    public function filter(array $params): string
    {
        return Gate::agate($params, 'downward');
    }
}
