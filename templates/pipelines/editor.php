<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var array<string, mixed> $pipeline */
/** @var array<string, mixed> $editorData */
$v->share('scripts', ['js/knobs.js', 'js/effects.js', 'js/pipeline-editor.js']);
$editorData['urls'] = [
    'save' => $v->url('/pipelines/save'),
    'presets' => $v->url('/presets'),
    'presetDelete' => $v->url('/presets/{id}/delete'),
    'previewUpload' => $v->url('/preview/upload'),
    'previewRender' => $v->url('/preview/render'),
];
$editorData['events'] = ['new_file', 'waiting_manual', 'waiting_review', 'success', 'failed', 'rejected'];
?>
<script type="application/json" id="aep-editor-data"><?= $v->json($editorData) ?></script>

<div class="aep-page-header">
    <div class="text-truncate">
        <div class="small text-body-secondary"><a href="<?= $v->url('/pipelines') ?>"><?= $v->te('nav.pipelines') ?></a> / <?= $pipeline['id'] === null ? $v->te('pipelines.new') : '#' . (int) $pipeline['id'] ?></div>
        <h1 class="h4 mb-0 text-truncate" data-editor-title><?= $v->e($pipeline['name'] !== '' ? $pipeline['name'] : $v->t('pipelines.new')) ?></h1>
    </div>
    <div class="d-flex gap-2">
        <?php if ($pipeline['id'] !== null): ?>
            <a class="btn btn-outline-secondary" href="<?= $v->url('/upload?pipeline=' . (int) $pipeline['id']) ?>"><i class="bi bi-cloud-arrow-up me-1"></i><?= $v->te('pipelines.test_upload') ?></a>
        <?php endif; ?>
        <button class="btn btn-primary" type="button" data-editor-save><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button>
    </div>
</div>

<div class="alert alert-danger d-none" data-editor-error role="alert"></div>

<div class="row g-3">
    <div class="col-xl-3 order-2 order-xl-1">
        <div class="aep-panel mb-3">
            <div class="aep-panel-title"><i class="bi bi-info-circle me-2"></i><?= $v->te('pipelines.general') ?></div>
            <div class="vstack gap-3">
                <div>
                    <label class="form-label" for="pl-name"><?= $v->te('common.name') ?></label>
                    <input class="form-control" id="pl-name" data-field="name" required maxlength="190">
                </div>
                <div>
                    <label class="form-label" for="pl-description"><?= $v->te('common.description') ?></label>
                    <textarea class="form-control" id="pl-description" data-field="description" rows="2"></textarea>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="pl-enabled" data-field="enabled">
                    <label class="form-check-label" for="pl-enabled"><?= $v->te('common.enabled') ?></label>
                </div>
                <div>
                    <label class="form-label" for="pl-source"><?= $v->te('pipelines.source') ?></label>
                    <select class="form-select" id="pl-source" data-field="source_id"></select>
                    <div class="form-text"><?= $v->te('pipelines.source_hint') ?></div>
                </div>
                <div>
                    <label class="form-label" for="pl-template"><?= $v->te('pipelines.metadata_template') ?></label>
                    <select class="form-select" id="pl-template" data-field="metadata_template_id"></select>
                </div>
            </div>
        </div>
        <div class="aep-panel">
            <div class="aep-panel-title"><i class="bi bi-envelope me-2"></i><?= $v->te('pipelines.notifications') ?></div>
            <div class="vstack gap-2" data-notifications>
                <div class="small text-body-secondary"><?= $v->te('pipelines.notify_on') ?></div>
                <div data-notify-events></div>
                <div>
                    <label class="form-label" for="pl-recipients"><?= $v->te('pipelines.recipients') ?></label>
                    <textarea class="form-control font-monospace small" id="pl-recipients" data-notify-recipients rows="2" placeholder="studio@example.com, editor@example.com"></textarea>
                </div>
                <div>
                    <div class="small text-body-secondary mb-1"><?= $v->te('pipelines.notify_roles') ?></div>
                    <div data-notify-roles></div>
                    <div class="form-text"><?= $v->te('pipelines.notify_roles_hint') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-9 order-1 order-xl-2">
        <div class="aep-panel mb-3">
            <div class="aep-panel-title d-flex align-items-center">
                <span><i class="bi bi-diagram-3 me-2"></i><?= $v->te('pipelines.stages') ?></span>
                <span class="ms-auto small text-body-secondary d-none d-md-inline"><?= $v->te('pipelines.drag_hint') ?></span>
            </div>
            <div class="aep-editor-graph" data-stage-graph></div>
            <div class="dropdown mt-3">
                <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-plus-lg me-1"></i><?= $v->te('pipelines.add_stage') ?>
                </button>
                <div class="dropdown-menu aep-stage-palette p-2" data-stage-palette></div>
            </div>
        </div>

        <div class="aep-panel aep-stage-detail" data-stage-detail>
            <div class="aep-empty small"><i class="bi bi-hand-index"></i><p><?= $v->te('pipelines.select_stage') ?></p></div>
        </div>

        <div class="aep-panel mt-3 aep-preview" data-preview>
            <div class="aep-panel-title"><i class="bi bi-headphones me-2"></i><?= $v->te('preview.title') ?></div>
            <p class="small text-body-secondary mb-2"><?= $v->te('preview.hint', ['seconds' => \AudiEdiPipe\Application\Preview\PreviewService::MAX_SECONDS]) ?></p>
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <label class="btn btn-sm btn-outline-secondary mb-0">
                    <i class="bi bi-upload me-1"></i><?= $v->te('preview.choose_file') ?>
                    <input type="file" class="visually-hidden" accept="audio/*,.wav,.mp3,.flac,.m4a,.aac,.ogg,.opus,.aif,.aiff" data-preview-file>
                </label>
                <span class="small text-body-secondary text-truncate" data-preview-name><?= $v->te('preview.no_file') ?></span>
                <div class="btn-group btn-group-sm ms-md-auto" role="group" aria-label="A/B">
                    <button type="button" class="btn btn-outline-secondary" data-preview-play="source" disabled>A · <?= $v->te('jobs.original') ?></button>
                    <button type="button" class="btn btn-outline-secondary" data-preview-play="stage" disabled>B · <?= $v->te('preview.this_stage') ?></button>
                    <button type="button" class="btn btn-outline-secondary" data-preview-play="chain" disabled>C · <?= $v->te('preview.chain') ?></button>
                </div>
            </div>
            <audio controls preload="none" class="w-100" data-preview-audio></audio>
            <div class="small text-body-secondary mt-1" data-preview-status></div>
        </div>
    </div>
</div>
