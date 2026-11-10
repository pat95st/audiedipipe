<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Job;

use AudiEdiPipe\Application\Job\Command\CancelJob;
use AudiEdiPipe\Application\Job\Command\CreateJob;
use AudiEdiPipe\Application\Job\Command\DeleteJob;
use AudiEdiPipe\Application\Job\Command\RetryJob;
use AudiEdiPipe\Application\Job\Command\ReviewJob;
use AudiEdiPipe\Application\Job\Command\SubmitManualEdit;
use AudiEdiPipe\Application\Notification\Notifier;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Domain\Job\JobStatus;
use AudiEdiPipe\Domain\Job\StageStatus;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Support\Json;

final class JobCommandHandler implements HandlerProvider
{
    public const METADATA_FIELDS = ['title', 'artist', 'album', 'album_artist', 'genre', 'date', 'track', 'comment', 'description', 'copyright', 'language', 'episode', 'season'];

    public function __construct(
        private readonly Database $db,
        private readonly string $workDirectory,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    public static function handledMessages(): array
    {
        return [
            CreateJob::class => 'create',
            SubmitManualEdit::class => 'submitManualEdit',
            ReviewJob::class => 'review',
            RetryJob::class => 'retry',
            CancelJob::class => 'cancel',
            DeleteJob::class => 'delete',
        ];
    }

    public function create(CreateJob $command): int
    {
        $pipeline = $this->db->fetchOne('SELECT * FROM pipelines WHERE id = ?', [$command->pipelineId]);
        if ($pipeline === null) {
            throw new ValidationException('pipelines.error.not_found');
        }
        if (!is_file($command->inputPath)) {
            throw new ValidationException('jobs.error.input_missing');
        }
        $now = Database::now();
        $metadata = $command->metadata + ['basename' => pathinfo($command->originalName, PATHINFO_FILENAME)];
        $jobId = $this->db->insert('jobs', [
            'pipeline_id' => $command->pipelineId,
            'status' => JobStatus::Queued->value,
            'origin' => $command->origin,
            'source_id' => $command->sourceId,
            'original_name' => mb_substr($command->originalName, 0, 255),
            'input_path' => $command->inputPath,
            'current_stage' => 0,
            'metadata' => Json::encode($metadata),
            'created_by' => $command->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $position = 0;
        $this->db->insert('job_stages', [
            'job_id' => $jobId,
            'position' => $position++,
            'stage_key' => 'prepare',
            'type' => 'prepare',
            'name' => '',
            'status' => StageStatus::Pending->value,
            'params' => '{}',
        ]);
        foreach (Json::decode($pipeline['stages']) as $stage) {
            if (!is_array($stage) || ($stage['enabled'] ?? true) === false) {
                continue;
            }
            $this->db->insert('job_stages', [
                'job_id' => $jobId,
                'position' => $position++,
                'stage_key' => Json::str($stage, 'key', 'stage' . $position),
                'type' => Json::str($stage, 'type'),
                'name' => Json::str($stage, 'name'),
                'status' => StageStatus::Pending->value,
                'params' => Json::encode($stage['params'] ?? []),
            ]);
        }

        return $jobId;
    }

    public function submitManualEdit(SubmitManualEdit $command): void
    {
        [$job, $stage] = $this->waitingStage($command->jobId, JobStatus::WaitingManual, 'manual_edit');
        $cuts = [];
        foreach ($command->cuts as $cut) {
            if (!is_array($cut)) {
                continue;
            }
            $start = (float) ($cut['start'] ?? $cut[0] ?? 0);
            $end = (float) ($cut['end'] ?? $cut[1] ?? 0);
            if ($end > $start && $start >= 0) {
                $cuts[] = [round($start, 3), round($end, 3)];
            }
        }
        usort($cuts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $metadata = Json::decode($job['metadata']);
        foreach (self::METADATA_FIELDS as $field) {
            if (array_key_exists($field, $command->metadata)) {
                $metadata[$field] = mb_substr(trim((string) $command->metadata[$field]), 0, 5000);
            }
        }
        $params = Json::decode($stage['params']);
        $params['submission'] = ['cuts' => $cuts, 'user_id' => $command->userId, 'submitted_at' => Database::now()];

        $this->db->update('job_stages', [
            'params' => Json::encode($params),
            'status' => StageStatus::Pending->value,
            'actor_id' => $command->userId,
        ], ['id' => (int) $stage['id']]);
        $this->db->update('jobs', [
            'metadata' => Json::encode($metadata),
            'status' => JobStatus::Queued->value,
            'updated_at' => Database::now(),
        ], ['id' => $command->jobId]);
    }

    public function review(ReviewJob $command): void
    {
        [$job, $stage] = $this->waitingStage($command->jobId, JobStatus::WaitingReview, 'review');
        $params = Json::decode($stage['params']);
        if (!$command->approved && ($params['require_comment_on_reject'] ?? true) === true && trim($command->comment) === '') {
            throw new ValidationException('jobs.error.comment_required');
        }
        $params['decision'] = [
            'approved' => $command->approved,
            'comment' => mb_substr(trim($command->comment), 0, 5000),
            'user_id' => $command->userId,
            'decided_at' => Database::now(),
        ];
        $now = Database::now();
        if ($command->approved) {
            $this->db->update('job_stages', [
                'params' => Json::encode($params),
                'status' => StageStatus::Pending->value,
                'actor_id' => $command->userId,
            ], ['id' => (int) $stage['id']]);
            $this->db->update('jobs', ['status' => JobStatus::Queued->value, 'updated_at' => $now], ['id' => $command->jobId]);

            return;
        }
        $this->db->update('job_stages', [
            'params' => Json::encode($params),
            'status' => StageStatus::Rejected->value,
            'actor_id' => $command->userId,
            'finished_at' => $now,
            'log' => trim((string) ($stage['log'] ?? '') . "\nRejected: " . $command->comment),
        ], ['id' => (int) $stage['id']]);
        $this->db->update('jobs', [
            'status' => JobStatus::Rejected->value,
            'error' => mb_substr(trim($command->comment), 0, 5000),
            'finished_at' => $now,
            'updated_at' => $now,
        ], ['id' => $command->jobId]);
        $this->notifier?->jobEvent((int) $job['id'], 'rejected');
    }

    public function retry(RetryJob $command): void
    {
        $job = $this->job($command->jobId);
        $status = JobStatus::from((string) $job['status']);
        if (!in_array($status, [JobStatus::Failed, JobStatus::Rejected, JobStatus::Canceled], true)) {
            throw new ValidationException('jobs.error.not_retryable');
        }
        // Reset the first non-successful stage and everything after it.
        $stages = $this->db->fetchAll('SELECT * FROM job_stages WHERE job_id = ? ORDER BY position', [$command->jobId]);
        $reset = false;
        foreach ($stages as $stage) {
            if (!$reset && $stage['status'] !== StageStatus::Success->value && $stage['status'] !== StageStatus::Skipped->value) {
                $reset = true;
            }
            if ($reset) {
                $params = Json::decode($stage['params']);
                unset($params['decision']);
                $this->db->update('job_stages', [
                    'status' => StageStatus::Pending->value,
                    'params' => Json::encode($params),
                    'started_at' => null,
                    'finished_at' => null,
                ], ['id' => (int) $stage['id']]);
            }
        }
        $this->db->update('jobs', [
            'status' => JobStatus::Queued->value,
            'error' => null,
            'finished_at' => null,
            'locked_by' => null,
            'updated_at' => Database::now(),
        ], ['id' => $command->jobId]);
    }

    public function cancel(CancelJob $command): void
    {
        $job = $this->job($command->jobId);
        if (JobStatus::from((string) $job['status'])->isFinal()) {
            throw new ValidationException('jobs.error.already_finished');
        }
        $now = Database::now();
        $this->db->execute(
            "UPDATE job_stages SET status = 'canceled' WHERE job_id = ? AND status IN ('pending', 'waiting', 'running')",
            [$command->jobId],
        );
        $this->db->update('jobs', [
            'status' => JobStatus::Canceled->value,
            'finished_at' => $now,
            'updated_at' => $now,
            'locked_by' => null,
        ], ['id' => $command->jobId]);
    }

    public function delete(DeleteJob $command): void
    {
        $job = $this->job($command->jobId);
        if ($job['status'] === JobStatus::Running->value) {
            throw new ValidationException('jobs.error.running');
        }
        $this->db->delete('job_stages', ['job_id' => $command->jobId]);
        $this->db->delete('jobs', ['id' => $command->jobId]);
        self::removeDirectory($this->workDirectory . '/job_' . $command->jobId);
    }

    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @return array<string, mixed>
     */
    private function job(int $id): array
    {
        $job = $this->db->fetchOne('SELECT * FROM jobs WHERE id = ?', [$id]);
        if ($job === null) {
            throw new ValidationException('jobs.error.not_found');
        }

        return $job;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function waitingStage(int $jobId, JobStatus $expected, string $type): array
    {
        $job = $this->job($jobId);
        if ($job['status'] !== $expected->value) {
            throw new ValidationException('jobs.error.not_waiting');
        }
        $stage = $this->db->fetchOne(
            "SELECT * FROM job_stages WHERE job_id = ? AND status = 'waiting' AND type = ? ORDER BY position LIMIT 1",
            [$jobId, $type],
        );
        if ($stage === null) {
            throw new ValidationException('jobs.error.not_waiting');
        }

        return [$job, $stage];
    }
}
