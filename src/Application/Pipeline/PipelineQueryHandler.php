<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline;

use AudiEdiPipe\Application\Pipeline\Query\GetPipeline;
use AudiEdiPipe\Application\Pipeline\Query\ListPipelines;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Support\Json;

final class PipelineQueryHandler implements HandlerProvider
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function handledMessages(): array
    {
        return [
            ListPipelines::class => 'list',
            GetPipeline::class => 'get',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ListPipelines $query): array
    {
        $sql = 'SELECT p.*, s.name AS source_name,
                (SELECT COUNT(*) FROM jobs j WHERE j.pipeline_id = p.id) AS job_count,
                (SELECT MAX(j.created_at) FROM jobs j WHERE j.pipeline_id = p.id) AS last_job_at
                FROM pipelines p LEFT JOIN sources s ON s.id = p.source_id';
        if ($query->onlyEnabled) {
            $sql .= ' WHERE p.enabled = 1';
        }
        $rows = $this->db->fetchAll($sql . ' ORDER BY p.name');

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(GetPipeline $query): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM pipelines WHERE id = ?', [$query->id]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['stages'] = Json::decode($row['stages'] ?? '[]');
        $row['notifications'] = Json::decode($row['notifications'] ?? '{}') + ['events' => [], 'recipients' => '', 'roles' => []];
        $row['enabled'] = (bool) ($row['enabled'] ?? false);

        return $row;
    }
}
