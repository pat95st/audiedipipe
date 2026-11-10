<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Job\Command\CancelJob;
use AudiEdiPipe\Application\Job\Command\CreateJob;
use AudiEdiPipe\Application\Job\Command\DeleteJob;
use AudiEdiPipe\Application\Job\Command\RetryJob;
use AudiEdiPipe\Application\Job\Command\ReviewJob;
use AudiEdiPipe\Application\Job\Command\SubmitManualEdit;
use AudiEdiPipe\Application\Job\JobCommandHandler;
use AudiEdiPipe\Application\Job\Query\GetJob;
use AudiEdiPipe\Application\Job\Query\ListJobs;
use AudiEdiPipe\Application\Pipeline\Query\ListPipelines;
use AudiEdiPipe\Application\Template\Query\ListTemplates;
use AudiEdiPipe\Application\Template\TemplateRenderer;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Domain\Job\JobStatus;
use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\Audio\Ffmpeg;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Kernel\WorkerTrigger;
use Throwable;

final class JobController extends Controller
{
    public const ALLOWED_EXTENSIONS = ['wav', 'mp3', 'flac', 'm4a', 'aac', 'ogg', 'opus', 'aif', 'aiff', 'wma', 'mp4', 'webm'];

    private const MIME = [
        'wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'aif' => 'audio/aiff', 'aiff' => 'audio/aiff', 'webm' => 'audio/webm', 'mp4' => 'audio/mp4',
    ];

    public function index(): Response
    {
        $status = $this->request->string('status');
        $pipelineId = $this->request->int('pipeline') ?: null;
        $page = max(1, $this->request->int('page', 1));
        $perPage = 25;
        $result = $this->query(new ListJobs($status !== '' ? $status : null, $pipelineId, $perPage, ($page - 1) * $perPage));

        return $this->render('jobs/index', [
            'title' => $this->view->t('nav.jobs'),
            'jobs' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'status' => $status,
            'pipelineId' => $pipelineId,
            'pipelines' => $this->query(new ListPipelines()),
        ]);
    }

    public function statusFeed(): Response
    {
        $ids = array_filter(array_map('intval', explode(',', $this->request->string('ids'))));
        $result = [];
        foreach (array_slice($ids, 0, 50) as $id) {
            $job = $this->query(new GetJob($id));
            if (!is_array($job)) {
                continue;
            }
            $result[] = [
                'id' => $id,
                'status' => $job['status'],
                'stages' => array_map(static fn (array $s): array => ['position' => (int) $s['position'], 'status' => $s['status']], $job['stages']),
            ];
        }

        return Response::json(['jobs' => $result]);
    }

    public function uploadForm(): Response
    {
        $settings = $this->app->get(SettingsRepository::class);

        return $this->render('jobs/upload', [
            'title' => $this->view->t('nav.upload'),
            'pipelines' => $this->query(new ListPipelines(true)),
            'selected' => $this->request->int('pipeline'),
            'maxMb' => min((int) $settings->get('upload_max_mb'), self::iniBytes((string) ini_get('upload_max_filesize')) / 1048576, self::iniBytes((string) ini_get('post_max_size')) / 1048576),
        ]);
    }

    public function upload(): Response
    {
        $file = $this->request->file('file');
        $pipelineId = $this->request->int('pipeline_id');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('upload.error.failed', ['code' => $file['error'] ?? UPLOAD_ERR_NO_FILE]);
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ValidationException('upload.error.type');
        }
        $maxMb = (int) $this->app->get(SettingsRepository::class)->get('upload_max_mb');
        if ($file['size'] > $maxMb * 1048576) {
            throw new ValidationException('upload.error.size', ['max' => $maxMb]);
        }
        $dir = $this->app->storagePath('uploads/manual');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $safe = trim((string) preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($file['name'], PATHINFO_FILENAME)), '_') ?: 'audio';
        $target = $dir . '/' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safe . '.' . $extension;
        $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $target) : @rename($file['tmp_name'], $target);
        if (!$moved) {
            throw new ValidationException('upload.error.failed', ['code' => 'move']);
        }

        try {
            $this->app->get(Ffmpeg::class)->probe($target);
        } catch (Throwable) {
            @unlink($target);

            throw new ValidationException('upload.error.not_audio');
        }
        $jobId = (int) $this->command(new CreateJob($pipelineId, $file['name'], $target, 'upload', null, $this->userId()));
        WorkerTrigger::request();
        $this->flash('success', 'upload.started');

        if ($this->request->isJson()) {
            return Response::json(['id' => $jobId, 'redirect' => $this->view->url('/jobs/' . $jobId)]);
        }

        return $this->redirect('/jobs/' . $jobId);
    }

    public function show(): Response
    {
        $job = $this->query(new GetJob($this->request->intAttribute('id')));
        if (!is_array($job)) {
            return Response::notFound();
        }

        return $this->render('jobs/show', [
            'title' => '#' . $job['id'] . ' ' . $job['original_name'],
            'job' => $job,
            'status' => JobStatus::from((string) $job['status']),
        ]);
    }

    public function audio(): Response
    {
        $path = $this->resolveAudio($this->request->intAttribute('id'), $this->request->attribute('which'));
        if ($path === null) {
            return Response::notFound();
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return Response::file($path, self::MIME[$ext] ?? 'application/octet-stream', $this->request->header('range') ?: null);
    }

    public function download(): Response
    {
        $job = $this->query(new GetJob($this->request->intAttribute('id')));
        $path = is_array($job) ? (string) ($job['output_path'] ?? '') : '';
        if ($path === '' || !$this->insideStorage($path)) {
            return Response::notFound();
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return Response::file($path, self::MIME[$ext] ?? 'application/octet-stream', null, basename($path));
    }

    public function peaks(): Response
    {
        $jobId = $this->request->intAttribute('id');
        $which = $this->request->attribute('which');
        $path = $this->resolveAudio($jobId, $which);
        if ($path === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $cache = $this->app->storagePath('work/job_' . $jobId) . '/peaks_' . md5($path . filemtime($path)) . '.json';
        if (!is_file($cache)) {
            try {
                $probe = $this->app->get(Ffmpeg::class)->probe($path);
                $peaks = $this->app->get(Ffmpeg::class)->peaks($path, 4000);
            } catch (Throwable $e) {
                return Response::json(['error' => $e->getMessage()], 500);
            }
            @mkdir(dirname($cache), 0775, true);
            @file_put_contents($cache, (string) json_encode(['duration' => $probe['duration'], 'peaks' => $peaks]));
        }

        return Response::text((string) file_get_contents($cache), 200, 'application/json');
    }

    public function editForm(): Response
    {
        $job = $this->query(new GetJob($this->request->intAttribute('id')));
        if (!is_array($job)) {
            return Response::notFound();
        }
        if ($job['status'] !== JobStatus::WaitingManual->value) {
            $this->flash('warning', 'jobs.error.not_waiting');

            return $this->redirect('/jobs/' . $job['id']);
        }
        $stage = null;
        foreach ($job['stages'] as $s) {
            if ($s['type'] === 'manual_edit' && $s['status'] === 'waiting') {
                $stage = $s;
            }
        }
        $templates = $this->query(new ListTemplates());
        $renderer = $this->app->get(TemplateRenderer::class);
        $vars = $renderer->variables((int) $job['id'], $job['metadata']);
        foreach ($templates as &$template) {
            foreach ($template['fields'] as $field => $value) {
                $template['fields'][$field] = TemplateRenderer::render((string) $value, $vars);
            }
        }
        unset($template);

        return $this->render('jobs/edit', [
            'title' => $this->view->t('edit.title') . ' #' . $job['id'],
            'job' => $job,
            'stage' => $stage,
            'fields' => JobCommandHandler::METADATA_FIELDS,
            'templates' => $templates,
        ]);
    }

    public function submitEdit(): Response
    {
        $jobId = $this->request->intAttribute('id');
        $data = $this->request->json();
        $this->command(new SubmitManualEdit(
            $jobId,
            $this->userId(),
            is_array($data['cuts'] ?? null) ? $data['cuts'] : [],
            is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        ));
        WorkerTrigger::request();
        $this->flash('success', 'edit.submitted');

        return Response::json(['redirect' => $this->view->url('/jobs/' . $jobId)]);
    }

    public function review(): Response
    {
        $jobId = $this->request->intAttribute('id');
        $approved = $this->request->string('decision') === 'approve';
        $this->command(new ReviewJob($jobId, $this->userId(), $approved, $this->request->string('comment')));
        if ($approved) {
            WorkerTrigger::request();
        }
        $this->flash('success', $approved ? 'review.approved' : 'review.rejected');

        return $this->redirect('/jobs/' . $jobId);
    }

    public function retry(): Response
    {
        $jobId = $this->request->intAttribute('id');
        $this->command(new RetryJob($jobId));
        WorkerTrigger::request();
        $this->flash('success', 'jobs.retried');

        return $this->redirect('/jobs/' . $jobId);
    }

    public function cancel(): Response
    {
        $jobId = $this->request->intAttribute('id');
        $this->command(new CancelJob($jobId));
        $this->flash('success', 'jobs.canceled');

        return $this->redirect('/jobs/' . $jobId);
    }

    public function delete(): Response
    {
        $this->command(new DeleteJob($this->request->intAttribute('id')));
        $this->flash('success', 'common.deleted');

        return $this->redirect('/jobs');
    }

    private function resolveAudio(int $jobId, string $which): ?string
    {
        $job = $this->query(new GetJob($jobId));
        if (!is_array($job)) {
            return null;
        }
        $path = match (true) {
            $which === 'input' => (string) $job['input_path'],
            $which === 'current' => (string) ($job['current_file'] ?? ''),
            $which === 'output' => (string) ($job['output_path'] ?? ''),
            ctype_digit($which) => (static function () use ($job, $which): string {
                foreach ($job['stages'] as $stage) {
                    if ((int) $stage['position'] === (int) $which) {
                        return (string) ($stage['output_path'] ?? '');
                    }
                }

                return '';
            })(),
            default => '',
        };
        if ($path === '' || !is_file($path) || !$this->insideStorage($path)) {
            return null;
        }

        return $path;
    }

    private function insideStorage(string $path): bool
    {
        $real = realpath($path);
        $base = realpath($this->app->storagePath());

        return $real !== false && $base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
