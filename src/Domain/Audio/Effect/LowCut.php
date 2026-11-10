<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Butterworth high-pass ("low cut") with selectable slope (12 dB/oct per stage).
 */
final class LowCut extends AbstractEffect
{
    public function type(): string
    {
        return 'low_cut';
    }

    public function schema(): array
    {
        return [
            'frequency' => ['type' => 'number', 'min' => 10, 'max' => 500, 'step' => 1, 'default' => 80, 'unit' => 'Hz'],
            'slope' => ['type' => 'select', 'options' => [12, 24, 36, 48], 'default' => 24],
        ];
    }

    public function normalize(array $params): array
    {
        if (isset($params['slope']) && is_numeric($params['slope'])) {
            $params['slope'] = (int) $params['slope'];
        }

        return parent::normalize($params);
    }

    public function filter(array $params): string
    {
        $stages = max(1, intdiv((int) ($params['slope'] ?? 24), 12));
        $single = sprintf('highpass=f=%s:p=2:t=q:w=0.707', self::num(self::f($params, 'frequency'), 2));

        return implode(',', array_fill(0, $stages, $single));
    }
}
