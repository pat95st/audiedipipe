<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Pipeline\Command\DeletePipeline;
use AudiEdiPipe\Application\Pipeline\Command\DuplicatePipeline;
use AudiEdiPipe\Application\Pipeline\Command\SavePipeline;
use AudiEdiPipe\Application\Pipeline\Query\GetPipeline;
use AudiEdiPipe\Application\Pipeline\Query\ListPipelines;
use AudiEdiPipe\Application\Publish\Query\ListPublishTargets;
use AudiEdiPipe\Application\Source\Query\ListSources;
use AudiEdiPipe\Application\Template\Query\ListPresets;
use AudiEdiPipe\Application\Template\Query\ListTemplates;
use AudiEdiPipe\Domain\Pipeline\StageCatalog;
use AudiEdiPipe\Http\Response;

final class PipelineController extends Controller
{
    public function index(): Response
    {
        return $this->render('pipelines/index', [
            'title' => $this->view->t('nav.pipelines'),
            'pipelines' => $this->query(new ListPipelines()),
        ]);
    }

    public function create(): Response
    {
        $catalog = $this->app->get(StageCatalog::class);
        $defaultStages = [];
        foreach (['noise_reduction', 'low_cut', 'parametric_eq', 'compressor', 'master_leveler', 'manual_edit', 'review', 'export'] as $i => $type) {
            $defaultStages[] = ['key' => 's' . ($i + 1), 'type' => $type, 'name' => '', 'enabled' => true, 'params' => $catalog->defaults($type)];
        }

        return $this->editor([
            'id' => null,
            'name' => '',
            'description' => '',
            'enabled' => true,
            'source_id' => null,
            'metadata_template_id' => null,
            'stages' => $defaultStages,
            'notifications' => ['events' => ['waiting_manual', 'waiting_review', 'failed'], 'recipients' => '', 'roles' => []],
        ]);
    }

    public function edit(): Response
    {
        $pipeline = $this->query(new GetPipeline($this->request->intAttribute('id')));
        if (!is_array($pipeline)) {
            return Response::notFound();
        }

        return $this->editor($pipeline);
    }

    public function save(): Response
    {
        $data = $this->request->json();
        $id = is_numeric($data['id'] ?? null) && (int) $data['id'] > 0 ? (int) $data['id'] : null;
        $id = $this->command(new SavePipeline(
            $id,
            (string) ($data['name'] ?? ''),
            (string) ($data['description'] ?? ''),
            ($data['enabled'] ?? true) !== false,
            is_numeric($data['source_id'] ?? null) ? (int) $data['source_id'] : null,
            is_numeric($data['metadata_template_id'] ?? null) ? (int) $data['metadata_template_id'] : null,
            is_array($data['stages'] ?? null) ? $data['stages'] : [],
            is_array($data['notifications'] ?? null) ? $data['notifications'] : [],
            $this->userId(),
        ));
        $this->flash('success', 'common.saved');

        return Response::json(['id' => $id, 'redirect' => $this->view->url('/pipelines/' . $id)]);
    }

    public function delete(): Response
    {
        $this->command(new DeletePipeline($this->request->intAttribute('id')));
        $this->flash('success', 'common.deleted');

        return $this->redirect('/pipelines');
    }

    public function duplicate(): Response
    {
        $id = $this->command(new DuplicatePipeline($this->request->intAttribute('id'), $this->userId()));
        $this->flash('success', 'pipelines.duplicated');

        return $this->redirect('/pipelines/' . (int) $id);
    }

    /**
     * @param array<string, mixed> $pipeline
     */
    private function editor(array $pipeline): Response
    {
        $catalog = $this->app->get(StageCatalog::class);

        return $this->render('pipelines/editor', [
            'title' => $pipeline['id'] === null ? $this->view->t('pipelines.new') : (string) $pipeline['name'],
            'pipeline' => $pipeline,
            'editorData' => [
                'pipeline' => $pipeline,
                'types' => $catalog->types(),
                'effects' => $catalog->effects()->export(),
                'workflowDefaults' => [
                    'manual_edit' => $catalog->defaults('manual_edit'),
                    'review' => $catalog->defaults('review'),
                    'notify' => $catalog->defaults('notify'),
                    'export' => $catalog->defaults('export'),
                    'publish' => $catalog->defaults('publish'),
                ],
                'sources' => array_map(static fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'type' => $s['type']], $this->query(new ListSources())),
                'templates' => array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name']], $this->query(new ListTemplates())),
                'targets' => array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'type' => $t['type']], $this->query(new ListPublishTargets())),
                'presets' => $this->query(new ListPresets()),
                'formats' => StageCatalog::FORMATS,
            ],
        ]);
    }
}
