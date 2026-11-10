<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine;

use AudiEdiPipe\Application\Notification\Notifier;
use AudiEdiPipe\Domain\Job\JobStatus;
use AudiEdiPipe\Domain\Job\StageStatus;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Support\Json;
use Throwable;

/**
 * Executes queued jobs stage by stage. Designed to be called repeatedly by
 * cron / web cron with a time budget, so it also works on shared hosting
 * with execution time limits: a job resumes with its next pending stage.
 */
final class PipelineRunner
{
    private const STALE_LOCK_SECONDS = 7200;

    private string $workerId;

    /**
     * @param list<StageHandler> $handlers
     */
    public function __construct(
        private readonly Database $db,
        private readonly array $handlers,
        private readonly string $workDirectory,
        private readonly ?Notifier $notifier = null,
    ) {
        $this->workerId = substr(bin2hex(random_bytes(8)), 0, 16);
    }

    /**
     * Process jobs until the time budget is used up or the queue is empty.
     *
     * @return int number of stages executed
     */
    public function tick(int $timeBudgetSeconds = 50): int
    {
        $deadline = time() + max(1, $timeBudgetSeconds);
        $this->releaseStaleLocks();
        $executed = 0;
        while (time() < $deadline) {
            $jobId = $this->claimNextJob();
            if ($jobId === null) {
                break;
            }
            $executed += $this->runJob($jobId, $deadline);
        }

        return $executed;
    }

    /**
     * Run stages of a claimed job until it waits, fails, finishes or time runs out.
     */
    public function runJob(int $jobId, int $deadline): int
    {
        $executed = 0;
        for (;;) {
            $job = $this->db->fetchOne(
                'SELECT j.*, p.metadata_template_id FROM jobs j JOIN pipelines p ON p.id = j.pipeline_id WHERE j.id = ?',
                [$jobId],
            );
            if ($job === null || $job['status'] !== JobStatus::Running->value) {
                return $executed;
            }
            $stage = $this->db->fetchOne(
                "SELECT * FROM job_stages WHERE job_id = ? AND status IN ('pending', 'running') ORDER BY position LIMIT 1",
                [$jobId],
            );
            if ($stage === null) {
                $this->finishJob($jobId);

                return $executed;
            }
            if (time() >= $deadline && $executed > 0) {
                $this->releaseJob($jobId, JobStatus::Queued);

                return $executed;
            }
            ++$executed;
            if (!$this->runStage($job, $stage)) {
                return $executed;
            }
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $stage
     *
     * @return bool true = continue with the next stage
     */
    private function runStage(array $job, array $stage): bool
    {
        $jobId = (int) $job['id'];
        $stageId = (int) $stage['id'];
        $now = Database::now();
        $this->db->update('job_stages', ['status' => StageStatus::Running->value, 'started_at' => $now], ['id' => $stageId]);
        $this->db->update('jobs', ['current_stage' => (int) $stage['position'], 'updated_at' => $now], ['id' => $jobId]);

        $workDir = $this->workDirectory . '/job_' . $jobId;
        if (!is_dir($workDir)) {
            @mkdir($workDir, 0775, true);
        }
        $metadata = Json::decode($job['metadata']);
        $context = new StageContext(
            $job,
            $stage,
            Json::decode($stage['params']),
            $job['current_file'] !== null ? (string) $job['current_file'] : null,
            $workDir,
            (int) ($metadata['_sample_rate'] ?? 48000) ?: 48000,
            $metadata,
        );

        try {
            $handler = $this->handlerFor((string) $stage['type']);
            $outcome = $handler === null
                ? StageOutcome::failed('No handler for stage type ' . $stage['type'])
                : $handler->run($context);
        } catch (Throwable $e) {
            $outcome = StageOutcome::failed($e->getMessage());
        }
        if ($outcome->message !== '') {
            $context->log($outcome->message);
        }
        $log = trim((string) ($stage['log'] ?? '') . "\n" . $context->logText());
        $finished = Database::now();
        $jobUpdate = ['metadata' => Json::encode($context->metadata), 'updated_at' => $finished];

        switch ($outcome->result) {
            case 'success':
            case 'skipped':
                $stageUpdate = [
                    'status' => $outcome->result === 'success' ? StageStatus::Success->value : StageStatus::Skipped->value,
                    'finished_at' => $finished,
                    'log' => $log,
                ];
                if ($outcome->outputFile !== null) {
                    $stageUpdate['output_path'] = $outcome->outputFile;
                    $jobUpdate['current_file'] = $outcome->outputFile;
                }
                if ($outcome->finalOutput !== null) {
                    $stageUpdate['output_path'] = $outcome->finalOutput;
                    $jobUpdate['output_path'] = $outcome->finalOutput;
                }
                $this->db->update('job_stages', $stageUpdate, ['id' => $stageId]);
                $this->db->update('jobs', $jobUpdate, ['id' => $jobId]);

                return true;

            case 'waiting':
                $this->db->update('job_stages', ['status' => StageStatus::Waiting->value, 'log' => $log], ['id' => $stageId]);
                $status = $outcome->waitStatus ?? JobStatus::WaitingManual;
                $jobUpdate['status'] = $status->value;
                $jobUpdate['locked_by'] = null;
                $this->db->update('jobs', $jobUpdate, ['id' => $jobId]);
                $this->notifier?->jobEvent($jobId, $status->value);

                return false;

            default:
                $this->db->update('job_stages', [
                    'status' => StageStatus::Failed->value,
                    'finished_at' => $finished,
                    'log' => $log,
                ], ['id' => $stageId]);
                $this->db->execute("UPDATE job_stages SET status = 'skipped' WHERE job_id = ? AND status = 'pending'", [$jobId]);
                $jobUpdate += [
                    'status' => JobStatus::Failed->value,
                    'error' => mb_substr($outcome->message, 0, 5000),
                    'finished_at' => $finished,
                    'locked_by' => null,
                ];
                $this->db->update('jobs', $jobUpdate, ['id' => $jobId]);
                $this->notifier?->jobEvent($jobId, 'failed');

                return false;
        }
    }

    private function handlerFor(string $type): ?StageHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($type)) {
                return $handler;
            }
        }

        return null;
    }

    private function claimNextJob(): ?int
    {
        $candidates = $this->db->fetchAll("SELECT id FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 5");
        foreach ($candidates as $row) {
            $claimed = $this->db->execute(
                "UPDATE jobs SET status = 'running', locked_by = ?, locked_at = ?, updated_at = ? WHERE id = ? AND status = 'queued'",
                [$this->workerId, Database::now(), Database::now(), (int) $row['id']],
            );
            if ($claimed === 1) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private function finishJob(int $jobId): void
    {
        $now = Database::now();
        $this->db->update('jobs', [
            'status' => JobStatus::Success->value,
            'finished_at' => $now,
            'updated_at' => $now,
            'locked_by' => null,
        ], ['id' => $jobId]);
        $this->notifier?->jobEvent($jobId, 'success');
    }

    private function releaseJob(int $jobId, JobStatus $status): void
    {
        $this->db->update('jobs', ['status' => $status->value, 'locked_by' => null, 'updated_at' => Database::now()], ['id' => $jobId]);
    }

    /**
     * Jobs whose worker crashed (e.g. killed by the host) are re-queued.
     */
    private function releaseStaleLocks(): void
    {
        $limit = gmdate('Y-m-d H:i:s', time() - self::STALE_LOCK_SECONDS);
        $stale = $this->db->fetchAll("SELECT id FROM jobs WHERE status = 'running' AND locked_at < ?", [$limit]);
        foreach ($stale as $row) {
            $this->db->execute("UPDATE job_stages SET status = 'pending' WHERE job_id = ? AND status = 'running'", [(int) $row['id']]);
            $this->releaseJob((int) $row['id'], JobStatus::Queued);
        }
    }
}
