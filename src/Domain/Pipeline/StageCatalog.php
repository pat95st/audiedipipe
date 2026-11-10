<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Pipeline;

use AudiEdiPipe\Domain\Audio\Effect\EffectRegistry;
use InvalidArgumentException;

/**
 * Knows every stage type a pipeline may contain and validates stage definitions.
 */
final class StageCatalog
{
    public const FORMATS = ['mp3', 'aac', 'opus', 'ogg', 'flac', 'wav'];

    /** Non-effect stage types with their categories and icons. */
    private const WORKFLOW_STAGES = [
        'manual_edit' => ['category' => 'manual', 'icon' => 'bi-scissors'],
        'review' => ['category' => 'manual', 'icon' => 'bi-clipboard-check'],
        'notify' => ['category' => 'deliver', 'icon' => 'bi-envelope'],
        'export' => ['category' => 'deliver', 'icon' => 'bi-file-earmark-music'],
        'publish' => ['category' => 'deliver', 'icon' => 'bi-broadcast'],
    ];

    private const EFFECT_META = [
        'noise_reduction' => ['category' => 'restore', 'icon' => 'bi-soundwave'],
        'low_cut' => ['category' => 'restore', 'icon' => 'bi-funnel'],
        'parametric_eq' => ['category' => 'eq', 'icon' => 'bi-sliders'],
        'dynamic_eq' => ['category' => 'eq', 'icon' => 'bi-sliders2-vertical'],
        'gate' => ['category' => 'dynamics', 'icon' => 'bi-door-closed'],
        'expander' => ['category' => 'dynamics', 'icon' => 'bi-arrows-expand'],
        'compressor' => ['category' => 'dynamics', 'icon' => 'bi-arrows-collapse'],
        'master_leveler' => ['category' => 'master', 'icon' => 'bi-speedometer2'],
    ];

    public function __construct(private readonly EffectRegistry $effects)
    {
    }

    public function effects(): EffectRegistry
    {
        return $this->effects;
    }

    public function isEffect(string $type): bool
    {
        return $this->effects->has($type);
    }

    public function exists(string $type): bool
    {
        return $this->isEffect($type) || isset(self::WORKFLOW_STAGES[$type]);
    }

    /**
     * @return array<string, array{category: string, icon: string, effect: bool}>
     */
    public function types(): array
    {
        $types = [];
        foreach (self::EFFECT_META as $type => $meta) {
            if ($this->effects->has($type)) {
                $types[$type] = $meta + ['effect' => true];
            }
        }
        foreach (self::WORKFLOW_STAGES as $type => $meta) {
            $types[$type] = $meta + ['effect' => false];
        }

        return $types;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(string $type): array
    {
        if ($this->isEffect($type)) {
            return $this->effects->get($type)->normalize([]);
        }

        return $this->normalizeWorkflowParams($type, []);
    }

    /**
     * Validate and normalize the full stage list of a pipeline.
     *
     * @param array<mixed> $stages
     *
     * @return list<array{key: string, type: string, name: string, enabled: bool, params: array<string, mixed>}>
     */
    public function normalizeStages(array $stages): array
    {
        $result = [];
        $usedKeys = [];
        foreach (array_values($stages) as $index => $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $type = (string) ($stage['type'] ?? '');
            if (!$this->exists($type)) {
                throw new InvalidArgumentException('Unknown stage type: ' . $type);
            }
            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($stage['key'] ?? '')) ?? '';
            if ($key === '' || isset($usedKeys[$key])) {
                $key = 's' . ($index + 1) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            }
            $usedKeys[$key] = true;
            $params = is_array($stage['params'] ?? null) ? $stage['params'] : [];
            $result[] = [
                'key' => $key,
                'type' => $type,
                'name' => mb_substr(trim((string) ($stage['name'] ?? '')), 0, 190),
                'enabled' => ($stage['enabled'] ?? true) !== false,
                'params' => $this->isEffect($type)
                    ? $this->effects->get($type)->normalize($params)
                    : $this->normalizeWorkflowParams($type, $params),
            ];
        }

        return $result;
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    public function normalizeWorkflowParams(string $type, array $params): array
    {
        return match ($type) {
            'manual_edit' => [
                'template_id' => self::nullableInt($params['template_id'] ?? null),
                'instructions' => mb_substr((string) ($params['instructions'] ?? ''), 0, 2000),
            ],
            'review' => [
                'instructions' => mb_substr((string) ($params['instructions'] ?? ''), 0, 2000),
                'require_comment_on_reject' => ($params['require_comment_on_reject'] ?? true) !== false,
            ],
            'notify' => [
                'recipients' => mb_substr((string) ($params['recipients'] ?? ''), 0, 2000),
                'roles' => array_values(array_intersect(['admin', 'cutter', 'reviewer'], (array) ($params['roles'] ?? []))),
                'subject' => mb_substr((string) ($params['subject'] ?? ''), 0, 255),
                'message' => mb_substr((string) ($params['message'] ?? ''), 0, 5000),
                'attach_link' => ($params['attach_link'] ?? true) !== false,
            ],
            'export' => [
                'format' => in_array($params['format'] ?? null, self::FORMATS, true) ? (string) $params['format'] : 'mp3',
                'bitrate' => max(32, min(320, (int) ($params['bitrate'] ?? 128))),
                'sample_rate' => in_array((int) ($params['sample_rate'] ?? 0), [0, 22050, 44100, 48000], true) ? (int) ($params['sample_rate'] ?? 0) : 0,
                'channels' => in_array((int) ($params['channels'] ?? 0), [0, 1, 2], true) ? (int) ($params['channels'] ?? 0) : 0,
                'filename' => mb_substr((string) ($params['filename'] ?? '{date}_{basename}'), 0, 255),
                'destination_id' => self::nullableInt($params['destination_id'] ?? null),
                'destination_path' => mb_substr((string) ($params['destination_path'] ?? ''), 0, 1000),
                'embed_cover' => ($params['embed_cover'] ?? true) !== false,
            ],
            'publish' => [
                'target_ids' => array_values(array_filter(
                    array_map('intval', (array) ($params['target_ids'] ?? [])),
                    static fn (int $id): bool => $id > 0,
                )),
            ],
            default => [],
        };
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
