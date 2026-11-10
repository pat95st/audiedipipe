<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Audio\Effect;

/**
 * Shared parameter handling. Schema entries:
 *   ['type' => 'number', 'min' => .., 'max' => .., 'step' => .., 'default' => .., 'unit' => 'dB']
 *   ['type' => 'select', 'options' => [...], 'default' => ..]
 *   ['type' => 'bool', 'default' => true]
 */
abstract class AbstractEffect implements AudioEffect
{
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->schema() as $name => $definition) {
            $defaults[$name] = $definition['default'] ?? null;
        }

        return $defaults;
    }

    public function normalize(array $params): array
    {
        return self::normalizeAgainst($this->schema(), $params);
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     * @param array<string, mixed>                $params
     *
     * @return array<string, mixed>
     */
    protected static function normalizeAgainst(array $schema, array $params): array
    {
        $result = [];
        foreach ($schema as $name => $definition) {
            $value = $params[$name] ?? $definition['default'] ?? null;
            $type = (string) ($definition['type'] ?? 'number');
            $result[$name] = match ($type) {
                'number' => self::clamp(
                    is_numeric($value) ? (float) $value : (float) ($definition['default'] ?? 0),
                    (float) ($definition['min'] ?? -PHP_FLOAT_MAX),
                    (float) ($definition['max'] ?? PHP_FLOAT_MAX),
                ),
                'bool' => in_array($value, [true, 1, '1', 'true', 'on'], true),
                'select' => in_array($value, (array) ($definition['options'] ?? []), true)
                    ? $value
                    : ($definition['default'] ?? null),
                default => $value,
            };
        }

        return $result;
    }

    protected static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    protected static function dbToLinear(float $db): float
    {
        return 10 ** ($db / 20);
    }

    /**
     * Format a float for ffmpeg filter arguments (dot decimal separator, no exponent).
     */
    protected static function num(float $value, int $decimals = 6): string
    {
        $formatted = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected static function f(array $params, string $key): float
    {
        $value = $params[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
