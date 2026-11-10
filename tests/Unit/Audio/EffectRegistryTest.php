<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Audio;

use AudiEdiPipe\Domain\Audio\Effect\EffectRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EffectRegistryTest extends TestCase
{
    private const EXPECTED = [
        'noise_reduction', 'low_cut', 'parametric_eq', 'compressor',
        'dynamic_eq', 'gate', 'expander', 'master_leveler',
    ];

    public function test_default_registry_contains_all_eight_effects(): void
    {
        $registry = EffectRegistry::default();
        foreach (self::EXPECTED as $type) {
            self::assertTrue($registry->has($type), "missing effect: $type");
        }
        self::assertCount(8, $registry->all());
    }

    public function test_get_unknown_effect_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EffectRegistry::default()->get('reverb');
    }

    public function test_export_provides_schema_and_defaults_for_every_effect(): void
    {
        $export = EffectRegistry::default()->export();
        foreach (self::EXPECTED as $type) {
            self::assertArrayHasKey($type, $export);
            self::assertArrayHasKey('schema', $export[$type]);
            self::assertArrayHasKey('defaults', $export[$type]);
        }
    }

    public function test_every_effect_normalizes_empty_params_to_defaults(): void
    {
        $registry = EffectRegistry::default();
        foreach ($registry->all() as $type => $effect) {
            $normalized = $effect->normalize([]);
            self::assertNotSame([], $normalized, "effect $type produced no defaults");
        }
    }
}
