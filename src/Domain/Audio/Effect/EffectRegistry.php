<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

use InvalidArgumentException;

final class EffectRegistry
{
    /** @var array<string, AudioEffect> */
    private array $effects = [];

    /**
     * @param list<AudioEffect> $effects
     */
    public function __construct(array $effects = [])
    {
        foreach ($effects as $effect) {
            $this->effects[$effect->type()] = $effect;
        }
    }

    public static function default(): self
    {
        return new self([
            new NoiseReduction(),
            new LowCut(),
            new ParametricEq(),
            new Compressor(),
            new DynamicEq(),
            new Gate(),
            new Expander(),
            new MasterLeveler(),
        ]);
    }

    public function has(string $type): bool
    {
        return isset($this->effects[$type]);
    }

    public function get(string $type): AudioEffect
    {
        return $this->effects[$type] ?? throw new InvalidArgumentException('Unknown effect: ' . $type);
    }

    /**
     * @return array<string, AudioEffect>
     */
    public function all(): array
    {
        return $this->effects;
    }

    /**
     * Schema + defaults for the UI.
     *
     * @return array<string, array{schema: array<string, array<string, mixed>>, defaults: array<string, mixed>}>
     */
    public function export(): array
    {
        $result = [];
        foreach ($this->effects as $type => $effect) {
            $result[$type] = ['schema' => $effect->schema(), 'defaults' => $effect->normalize([])];
        }

        return $result;
    }
}
