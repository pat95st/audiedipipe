<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Audio;

use AudiEdiPipe\Domain\Audio\Effect\Compressor;
use AudiEdiPipe\Domain\Audio\Effect\LowCut;
use AudiEdiPipe\Domain\Audio\Effect\ParametricEq;
use PHPUnit\Framework\TestCase;

final class EffectFilterTest extends TestCase
{
    public function test_low_cut_clamps_frequency_and_builds_stages_per_slope(): void
    {
        $effect = new LowCut();
        $params = $effect->normalize(['frequency' => 99999, 'slope' => 48]);

        self::assertSame(500.0, $params['frequency']); // clamped to max
        self::assertSame(48, $params['slope']);

        // 48 dB/oct -> four 12 dB/oct highpass stages
        self::assertSame(4, substr_count($effect->filter($params), 'highpass='));
    }

    public function test_low_cut_default_slope_is_two_stages(): void
    {
        $effect = new LowCut();
        $filter = $effect->filter($effect->normalize([]));
        self::assertSame(2, substr_count($filter, 'highpass='));
    }

    public function test_parametric_eq_emits_filter_only_for_enabled_bands_with_gain(): void
    {
        $effect = new ParametricEq();
        $params = $effect->normalize([
            'bands' => [
                ['enabled' => true, 'type' => 'bell', 'frequency' => 1000, 'gain' => 3.0, 'q' => 1.0],
                ['enabled' => false, 'type' => 'bell', 'frequency' => 2000, 'gain' => 6.0, 'q' => 1.0],
                ['enabled' => true, 'type' => 'bell', 'frequency' => 3000, 'gain' => 0.0, 'q' => 1.0],
            ],
        ]);
        $filter = $effect->filter($params);

        // only the first band (enabled, non-zero gain) yields an equalizer filter
        self::assertSame(1, substr_count($filter, 'equalizer='));
        self::assertStringContainsString('f=1000', $filter);
    }

    public function test_parametric_eq_output_gain_adds_volume_filter(): void
    {
        $effect = new ParametricEq();
        $params = $effect->normalize(['output_gain' => -3.0, 'bands' => []]);
        self::assertStringContainsString('volume=-3dB', $effect->filter($params));
    }

    public function test_compressor_filter_is_nonempty(): void
    {
        $effect = new Compressor();
        self::assertNotSame('', $effect->filter($effect->normalize([])));
    }
}
