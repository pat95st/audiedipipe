<?php

declare(strict_types=1);

namespace AudiEdiPipe\Kernel;

use AudiEdiPipe\Application\Job\JobCommandHandler;
use AudiEdiPipe\Application\Pipeline\Engine\PipelineRunner;
use AudiEdiPipe\Application\Preview\PreviewService;
use AudiEdiPipe\Application\Source\SourceScanner;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;

/**
 * One worker cycle: scan sources, run pipeline stages, clean up old files.
 * Uses a lock file so overlapping cron invocations do not collide.
 */
final class Worker
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array{scanned: int, imported: int, stages: int, skipped?: bool}
     */
    public function run(?int $timeBudget = null): array
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $lockFile = $this->app->storagePath('cache/worker.lock');
        @mkdir(dirname($lockFile), 0775, true);
        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['scanned' => 0, 'imported' => 0, 'stages' => 0, 'skipped' => true];
        }

        try {
            @file_put_contents($this->app->storagePath('cache/last_cron'), gmdate('Y-m-d H:i:s'));
            $scan = $this->app->get(SourceScanner::class)->scanDue();
            $budget = $timeBudget ?? $this->app->config->int('worker.time_budget', 50);
            $stages = $this->app->get(PipelineRunner::class)->tick($budget);
            $this->cleanup();

            return [
                'scanned' => count($scan),
                'imported' => array_sum(array_map(static fn (array $r): int => $r['imported'], $scan)),
                'stages' => $stages,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Should a pseudo cron run be triggered after this request?
     */
    public function pseudoCronDue(): bool
    {
        if (!$this->app->config->bool('worker.pseudo_cron')) {
            return false;
        }
        $last = @filemtime($this->app->storagePath('cache/last_cron'));

        return $last === false || $last < time() - 60;
    }

    /**
     * Remove intermediate files of finished jobs after the configured retention.
     */
    private function cleanup(): void
    {
        $marker = $this->app->storagePath('cache/last_cleanup');
        $last = @filemtime($marker);
        if ($last !== false && $last > time() - 3600) {
            return;
        }
        @touch($marker);
        $days = max(1, (int) $this->app->get(SettingsRepository::class)->get('keep_work_files_days'));
        $limit = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $jobs = $this->app->get(Database::class)->fetchAll(
            "SELECT id FROM jobs WHERE status IN ('success', 'failed', 'rejected', 'canceled') AND finished_at < ?",
            [$limit],
        );
        foreach ($jobs as $job) {
            JobCommandHandler::removeDirectory($this->app->storagePath('work/job_' . (int) $job['id']));
        }
        $this->app->get(PreviewService::class)->cleanup();
    }
}
