<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Two-pass EBU R128 loudness normalization (ffmpeg loudnorm) with true-peak limiting.
 */
final class MasterLeveler extends AbstractEffect implements TwoPassEffect
{
    public const PRESETS = [
        'podcast' => -16.0,
        'spotify' => -14.0,
        'podcast_mono' => -19.0,
        'ebu_r128' => -23.0,
        'atsc_a85' => -24.0,
    ];

    public function type(): string
    {
        return 'master_leveler';
    }

    public function schema(): array
    {
        return [
            'target' => ['type' => 'number', 'min' => -30, 'max' => -8, 'step' => 0.5, 'default' => -16, 'unit' => 'LUFS'],
            'true_peak' => ['type' => 'number', 'min' => -9, 'max' => 0, 'step' => 0.1, 'default' => -1.5, 'unit' => 'dBTP'],
            'lra' => ['type' => 'number', 'min' => 1, 'max' => 20, 'step' => 0.5, 'default' => 11, 'unit' => 'LU'],
            'dual_mono' => ['type' => 'bool', 'default' => false],
            'two_pass' => ['type' => 'bool', 'default' => true],
        ];
    }

    public function filter(array $params): string
    {
        return $this->base($params) . ':linear=false';
    }

    public function analysisFilter(array $params): string
    {
        return $this->base($params) . ':print_format=json';
    }

    public function parseAnalysis(string $output): array
    {
        $start = strrpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $data = json_decode(substr($output, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return [];
        }
        $result = [];
        foreach (['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $result[$key] = (float) $data[$key];
            }
        }

        return count($result) === 5 ? $result : [];
    }

    public function filterWithAnalysis(array $params, array $analysis): string
    {
        if (($params['two_pass'] ?? true) !== true || $analysis === []) {
            return $this->filter($params);
        }

        return sprintf(
            '%s:measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true:print_format=summary',
            $this->base($params),
            self::num(self::f($analysis, 'input_i'), 2),
            self::num(self::f($analysis, 'input_tp'), 2),
            self::num(self::f($analysis, 'input_lra'), 2),
            self::num(self::f($analysis, 'input_thresh'), 2),
            self::num(self::f($analysis, 'target_offset'), 2),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function base(array $params): string
    {
        return sprintf(
            'loudnorm=I=%s:TP=%s:LRA=%s:dual_mono=%s',
            self::num(self::f($params, 'target'), 1),
            self::num(self::f($params, 'true_peak'), 1),
            self::num(self::f($params, 'lra'), 1),
            ($params['dual_mono'] ?? false) === true ? 'true' : 'false',
        );
    }
}
