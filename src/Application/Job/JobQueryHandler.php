<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Job;

use AudiEdiPipe\Application\Job\Query\DashboardStats;
use AudiEdiPipe\Application\Job\Query\GetJob;
use AudiEdiPipe\Application\Job\Query\ListJobs;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Support\Json;

final class JobQueryHandler implements HandlerProvider
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function handledMessages(): array
    {
        return [
            ListJobs::class => 'list',
            GetJob::class => 'get',
            DashboardStats::class => 'stats',
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(ListJobs $query): array
    {
        $where = [];
        $params = [];
        $statuses = $query->statuses ?? ($query->status !== null && $query->status !== '' ? [$query->status] : []);
        if ($statuses !== []) {
            $where[] = 'j.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...array_map('strval', $statuses));
        }
        if ($query->pipelineId !== null) {
            $where[] = 'j.pipeline_id = ?';
            $params[] = $query->pipelineId;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs j' . $whereSql, $params);
        $limit = max(1, min(500, $query->limit));
        $offset = max(0, $query->offset);
        $items = $this->db->fetchAll(
            'SELECT j.id, j.pipeline_id, j.status, j.origin, j.original_name, j.created_at, j.updated_at, j.finished_at, j.error,
                    p.name AS pipeline_name
             FROM jobs j JOIN pipelines p ON p.id = j.pipeline_id' . $whereSql
            . ' ORDER BY j.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );
        if ($items !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $items);
            $stageRows = $this->db->fetchAll(
                'SELECT job_id, position, type, name, status FROM job_stages WHERE job_id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY job_id, position',
                $ids,
            );
            $byJob = [];
            foreach ($stageRows as $stage) {
                $byJob[(int) $stage['job_id']][] = $stage;
            }
            foreach ($items as &$item) {
                $item['stages'] = $byJob[(int) $item['id']] ?? [];
            }
            unset($item);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(GetJob $query): ?array
    {
        $job = $this->db->fetchOne(
            'SELECT j.*, p.name AS pipeline_name, p.metadata_template_id, u.username AS created_by_name
             FROM jobs j
             JOIN pipelines p ON p.id = j.pipeline_id
             LEFT JOIN users u ON u.id = j.created_by
             WHERE j.id = ?',
            [$query->id],
        );
        if ($job === null) {
            return null;
        }
        $job['metadata'] = Json::decode($job['metadata']);
        $stages = $this->db->fetchAll(
            'SELECT s.*, u.username AS actor_name FROM job_stages s LEFT JOIN users u ON u.id = s.actor_id
             WHERE s.job_id = ? ORDER BY s.position',
            [$query->id],
        );
        foreach ($stages as &$stage) {
            $stage['params'] = Json::decode($stage['params']);
        }
        unset($stage);
        $job['stages'] = $stages;

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(DashboardStats $query): array
    {
        $byStatus = [];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS c FROM jobs GROUP BY status') as $row) {
            $byStatus[(string) $row['status']] = (int) $row['c'];
        }
        $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $days = [];
        for ($i = 6; $i >= 0; --$i) {
            $days[gmdate('Y-m-d', time() - $i * 86400)] = 0;
        }
        foreach ($this->db->fetchAll('SELECT created_at FROM jobs WHERE created_at >= ?', [$since]) as $row) {
            $day = substr((string) $row['created_at'], 0, 10);
            if (isset($days[$day])) {
                ++$days[$day];
            }
        }

        return [
            'by_status' => $byStatus,
            'total' => array_sum($byStatus),
            'last_7_days' => $days,
            'pipelines' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM pipelines'),
            'sources' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM sources WHERE enabled = 1'),
        ];
    }
}
