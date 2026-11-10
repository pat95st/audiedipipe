<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline;

use AudiEdiPipe\Application\Pipeline\Command\DeletePipeline;
use AudiEdiPipe\Application\Pipeline\Command\DuplicatePipeline;
use AudiEdiPipe\Application\Pipeline\Command\SavePipeline;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Domain\Pipeline\StageCatalog;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Support\Json;
use InvalidArgumentException;

final class PipelineCommandHandler implements HandlerProvider
{
    public const NOTIFICATION_EVENTS = ['new_file', 'waiting_manual', 'waiting_review', 'success', 'failed', 'rejected'];

    public function __construct(private readonly Database $db, private readonly StageCatalog $catalog)
    {
    }

    public static function handledMessages(): array
    {
        return [
            SavePipeline::class => 'save',
            DeletePipeline::class => 'delete',
            DuplicatePipeline::class => 'duplicate',
        ];
    }

    public function save(SavePipeline $command): int
    {
        $name = trim($command->name);
        if ($name === '') {
            throw new ValidationException('pipelines.error.name_required');
        }

        try {
            $stages = $this->catalog->normalizeStages($command->stages);
        } catch (InvalidArgumentException $e) {
            throw new ValidationException('pipelines.error.invalid_stage', ['detail' => $e->getMessage()]);
        }
        $data = [
            'name' => mb_substr($name, 0, 190),
            'description' => $command->description,
            'enabled' => $command->enabled,
            'source_id' => $this->existingId('sources', $command->sourceId),
            'metadata_template_id' => $this->existingId('metadata_templates', $command->metadataTemplateId),
            'stages' => Json::encode($stages),
            'notifications' => Json::encode(self::normalizeNotifications($command->notifications)),
            'updated_at' => Database::now(),
        ];
        if ($command->id !== null) {
            if ($this->db->fetchValue('SELECT id FROM pipelines WHERE id = ?', [$command->id]) === null) {
                throw new ValidationException('pipelines.error.not_found');
            }
            $this->db->update('pipelines', $data, ['id' => $command->id]);

            return $command->id;
        }
        $data['created_by'] = $command->userId;
        $data['created_at'] = Database::now();

        return $this->db->insert('pipelines', $data);
    }

    public function delete(DeletePipeline $command): void
    {
        $active = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM jobs WHERE pipeline_id = ? AND status IN ('queued', 'running')",
            [$command->id],
        );
        if ($active > 0) {
            throw new ValidationException('pipelines.error.has_active_jobs');
        }
        $jobIds = $this->db->fetchAll('SELECT id FROM jobs WHERE pipeline_id = ?', [$command->id]);
        foreach ($jobIds as $row) {
            $this->db->delete('job_stages', ['job_id' => (int) $row['id']]);
        }
        $this->db->delete('jobs', ['pipeline_id' => $command->id]);
        $this->db->delete('pipelines', ['id' => $command->id]);
    }

    public function duplicate(DuplicatePipeline $command): int
    {
        $pipeline = $this->db->fetchOne('SELECT * FROM pipelines WHERE id = ?', [$command->id]);
        if ($pipeline === null) {
            throw new ValidationException('pipelines.error.not_found');
        }
        $now = Database::now();
        unset($pipeline['id']);
        $pipeline['name'] = mb_substr((string) $pipeline['name'] . ' (copy)', 0, 190);
        $pipeline['enabled'] = 0;
        $pipeline['source_id'] = null;
        $pipeline['created_by'] = $command->userId;
        $pipeline['created_at'] = $now;
        $pipeline['updated_at'] = $now;

        return $this->db->insert('pipelines', $pipeline);
    }

    /**
     * @param array<mixed> $input
     *
     * @return array{events: list<string>, recipients: string, roles: list<string>}
     */
    public static function normalizeNotifications(array $input): array
    {
        $events = array_values(array_intersect(self::NOTIFICATION_EVENTS, array_map('strval', (array) ($input['events'] ?? []))));
        $roles = array_values(array_intersect(['admin', 'cutter', 'reviewer'], array_map('strval', (array) ($input['roles'] ?? []))));
        $recipients = [];
        foreach (preg_split('/[\s,;]+/', (string) ($input['recipients'] ?? '')) ?: [] as $mail) {
            if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[] = strtolower($mail);
            }
        }

        return ['events' => $events, 'recipients' => implode(', ', array_unique($recipients)), 'roles' => $roles];
    }

    private function existingId(string $table, ?int $id): ?int
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        return $this->db->fetchValue('SELECT id FROM ' . $table . ' WHERE id = ?', [$id]) !== null ? $id : null;
    }
}
