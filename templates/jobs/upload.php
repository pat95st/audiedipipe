<?php /** @var \AudiEdiPipe\Http\View $v */ ?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('nav.upload') ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->te('upload.subtitle') ?></p>
    </div>
</div>

<?php if ($pipelines === []): ?>
    <div class="alert alert-info"><?= $v->te('upload.no_pipelines') ?>
        <?php if ($v->can('pipelines.manage')): ?> <a href="<?= $v->url('/pipelines/new') ?>"><?= $v->te('pipelines.new') ?></a><?php endif; ?>
    </div>
<?php else: ?>
<form class="aep-panel" method="post" action="<?= $v->url('/upload') ?>" enctype="multipart/form-data" data-upload-form>
    <?= $v->csrfField() ?>
    <div class="mb-3">
        <label class="form-label" for="pipeline_id"><?= $v->te('jobs.pipeline') ?></label>
        <select class="form-select" id="pipeline_id" name="pipeline_id" required>
            <?php foreach ($pipelines as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= $selected === (int) $p['id'] ? ' selected' : '' ?>><?= $v->e($p['name']) ?> (<?= count($p['stages']) ?> <?= $v->te('pipelines.stages') ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <label class="aep-dropzone" for="file" data-dropzone>
        <i class="bi bi-cloud-arrow-up display-5"></i>
        <span class="fw-semibold mt-2" data-dropzone-label><?= $v->te('upload.drop') ?></span>
        <span class="small text-body-secondary"><?= $v->te('upload.formats', ['max' => (int) $maxMb]) ?></span>
        <input class="visually-hidden" type="file" id="file" name="file" accept="audio/*,.wav,.mp3,.flac,.m4a,.aac,.ogg,.opus,.aif,.aiff" required>
    </label>
    <div class="progress mt-3 d-none" role="progressbar" aria-label="Upload" data-upload-progress>
        <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
    </div>
    <div class="d-flex justify-content-end mt-3">
        <button class="btn btn-primary" type="submit"><i class="bi bi-play-fill me-1"></i><?= $v->te('upload.start') ?></button>
    </div>
</form>
<?php endif; ?>
