<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Template;

use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Support\Json;

/**
 * Resolves placeholders in metadata templates:
 * {date} {year} {month} {day} {time} {basename} {filename} {pipeline} {counter} {job}
 * plus every metadata field, e.g. {title} or {episode}.
 */
final class TemplateRenderer
{
    public const PLACEHOLDERS = ['date', 'year', 'month', 'day', 'time', 'basename', 'filename', 'pipeline', 'counter', 'job'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Fill empty metadata fields of a job from a template.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function applyTemplate(int $templateId, int $jobId, array $metadata): array
    {
        $template = $this->db->fetchOne('SELECT fields FROM metadata_templates WHERE id = ?', [$templateId]);
        if ($template === null) {
            return $metadata;
        }
        $vars = $this->variables($jobId, $metadata);
        foreach (Json::decode($template['fields']) as $field => $value) {
            if (!is_string($field) || !is_scalar($value) || (string) $value === '') {
                continue;
            }
            if (($metadata[$field] ?? '') === '' || $field === 'cover') {
                $metadata[$field] = self::render((string) $value, $vars);
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, string>
     */
    public function variables(int $jobId, array $metadata): array
    {
        $job = $this->db->fetchOne(
            'SELECT j.original_name, j.pipeline_id, j.created_at, p.name AS pipeline_name FROM jobs j JOIN pipelines p ON p.id = j.pipeline_id WHERE j.id = ?',
            [$jobId],
        ) ?? [];
        $counter = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM jobs WHERE pipeline_id = ? AND id <= ?',
            [(int) ($job['pipeline_id'] ?? 0), $jobId],
        );
        $created = strtotime((string) ($job['created_at'] ?? 'now') . ' UTC') ?: time();
        $vars = [
            'date' => date('Y-m-d', $created),
            'year' => date('Y', $created),
            'month' => date('m', $created),
            'day' => date('d', $created),
            'time' => date('H-i', $created),
            'filename' => (string) ($job['original_name'] ?? ''),
            'basename' => pathinfo((string) ($job['original_name'] ?? ''), PATHINFO_FILENAME),
            'pipeline' => (string) ($job['pipeline_name'] ?? ''),
            'counter' => (string) $counter,
            'job' => (string) $jobId,
        ];
        foreach ($metadata as $key => $value) {
            if (is_string($key) && is_scalar($value) && !str_starts_with($key, '_') && !isset($vars[$key])) {
                $vars[$key] = (string) $value;
            }
        }

        return $vars;
    }

    /**
     * @param array<string, string> $vars
     */
    public static function render(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)(?::(\d+))?\}/', static function (array $m) use ($vars): string {
            if (!array_key_exists($m[1], $vars)) {
                return $m[0];
            }
            $value = $vars[$m[1]];
            // {counter:3} => zero padded
            if (isset($m[2]) && ctype_digit($value)) {
                return str_pad($value, (int) $m[2], '0', STR_PAD_LEFT);
            }

            return $value;
        }, $template);
    }
}
