<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var \AudiEdiPipe\Domain\Job\JobStatus $status */
$meta = $job['metadata'];
$stageMeta = [
    'prepare' => ['bi-box-arrow-in-down', 'prepare'],
    'noise_reduction' => ['bi-soundwave', 'restore'], 'low_cut' => ['bi-funnel', 'restore'],
    'parametric_eq' => ['bi-sliders', 'eq'], 'dynamic_eq' => ['bi-sliders2-vertical', 'eq'],
    'gate' => ['bi-door-closed', 'dynamics'], 'expander' => ['bi-arrows-expand', 'dynamics'], 'compressor' => ['bi-arrows-collapse', 'dynamics'],
    'master_leveler' => ['bi-speedometer2', 'master'],
    'manual_edit' => ['bi-scissors', 'manual'], 'review' => ['bi-clipboard-check', 'manual'],
    'notify' => ['bi-envelope', 'deliver'], 'export' => ['bi-file-earmark-music', 'deliver'], 'publish' => ['bi-broadcast', 'deliver'],
];
$waitingReview = $status === \AudiEdiPipe\Domain\Job\JobStatus::WaitingReview;
$reviewStage = null;
foreach ($job['stages'] as $s) {
    if ($s['type'] === 'review' && $s['status'] === 'waiting') {
        $reviewStage = $s;
    }
}
?>
<div class="aep-page-header" data-job-live="<?= (int) $job['id'] ?>" data-job-final="<?= $status->isFinal() ? '1' : '0' ?>" data-job-status-value="<?= $v->e($status->value) ?>">
    <div class="text-truncate">
        <div class="small text-body-secondary"><a href="<?= $v->url('/jobs') ?>"><?= $v->te('nav.jobs') ?></a> / #<?= (int) $job['id'] ?></div>
        <h1 class="h4 mb-1 text-truncate"><?= $v->e($job['original_name']) ?></h1>
        <div class="d-flex flex-wrap gap-2 align-items-center small text-body-secondary">
            <?= $v->partial('partials/job_status', ['status' => $status->value]) ?>
            <span><i class="bi bi-bezier2 me-1"></i><?= $v->e($job['pipeline_name']) ?></span>
            <span><i class="bi bi-clock me-1"></i><?= $v->date($job['created_at']) ?></span>
            <?php if (isset($meta['_duration'])): ?><span><i class="bi bi-stopwatch me-1"></i><?= $v->duration((float) $meta['_duration']) ?></span><?php endif; ?>
            <?php if ($job['created_by_name']): ?><span><i class="bi bi-person me-1"></i><?= $v->e($job['created_by_name']) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($status === \AudiEdiPipe\Domain\Job\JobStatus::WaitingManual && $v->can('jobs.edit')): ?>
            <a class="btn btn-warning" href="<?= $v->url('/jobs/' . $job['id'] . '/edit') ?>"><i class="bi bi-scissors me-1"></i><?= $v->te('jobs.open_editor') ?></a>
        <?php endif; ?>
        <?php if (($job['output_path'] ?? '') !== '' && $job['output_path'] !== null): ?>
            <a class="btn btn-outline-primary" href="<?= $v->url('/jobs/' . $job['id'] . '/download') ?>"><i class="bi bi-download me-1"></i><?= $v->te('jobs.download') ?></a>
        <?php endif; ?>
        <?php if (in_array($status->value, ['failed', 'rejected', 'canceled'], true) && $v->can('jobs.retry')): ?>
            <form method="post" action="<?= $v->url('/jobs/' . $job['id'] . '/retry') ?>"><?= $v->csrfField() ?><button class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i><?= $v->te('jobs.retry') ?></button></form>
        <?php endif; ?>
        <?php if (!$status->isFinal() && $v->can('jobs.retry')): ?>
            <form method="post" action="<?= $v->url('/jobs/' . $job['id'] . '/cancel') ?>" data-confirm="<?= $v->te('jobs.confirm_cancel') ?>"><?= $v->csrfField() ?><button class="btn btn-outline-secondary"><i class="bi bi-stop-circle me-1"></i><?= $v->te('common.cancel') ?></button></form>
        <?php endif; ?>
        <?php if ($v->can('jobs.delete') && $status !== \AudiEdiPipe\Domain\Job\JobStatus::Running): ?>
            <form method="post" action="<?= $v->url('/jobs/' . $job['id'] . '/delete') ?>" data-confirm="<?= $v->te('common.confirm_delete') ?>"><?= $v->csrfField() ?><button class="btn btn-outline-danger" aria-label="<?= $v->te('common.delete') ?>"><i class="bi bi-trash"></i></button></form>
        <?php endif; ?>
    </div>
</div>

<?php if (($job['error'] ?? '') !== '' && $job['error'] !== null): ?>
    <div class="alert alert-danger d-flex gap-2"><i class="bi bi-exclamation-octagon-fill"></i><div class="text-break"><?= nl2br($v->e($job['error'])) ?></div></div>
<?php endif; ?>

<!-- GitLab style pipeline graph -->
<div class="aep-panel mb-3">
    <div class="aep-panel-title"><i class="bi bi-diagram-3 me-2"></i><?= $v->te('jobs.pipeline_graph') ?></div>
    <div class="aep-pipeline-graph" role="list">
        <?php foreach ($job['stages'] as $stage):
            $ss = \AudiEdiPipe\Domain\Job\StageStatus::tryFrom((string) $stage['status']) ?? \AudiEdiPipe\Domain\Job\StageStatus::Pending;
            [$icon, $cat] = $stageMeta[$stage['type']] ?? ['bi-gear', 'other'];
            $label = $stage['name'] !== '' ? $stage['name'] : $v->t('stage.' . $stage['type']);
            ?>
            <div class="aep-graph-col" role="listitem">
                <div class="aep-graph-cat aep-cat-<?= $v->e($cat) ?>"><?= $v->te('category.' . $cat) ?></div>
                <a class="aep-graph-node aep-stage-<?= $v->e($ss->value) ?>" href="#stage-<?= (int) $stage['position'] ?>" data-stage-position="<?= (int) $stage['position'] ?>">
                    <span class="aep-graph-status"><i class="bi <?= $ss->icon() ?><?= $ss->value === 'running' ? ' aep-spin' : '' ?>"></i></span>
                    <span class="aep-graph-label"><i class="bi <?= $icon ?> me-1"></i><?= $v->e($label) ?></span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($waitingReview && $v->can('jobs.review')): ?>
    <div class="aep-panel mb-3 border-warning aep-review-panel">
        <div class="aep-panel-title"><i class="bi bi-clipboard-check me-2"></i><?= $v->te('review.title') ?></div>
        <?php if (($reviewStage['params']['instructions'] ?? '') !== ''): ?>
            <p class="small"><?= nl2br($v->e($reviewStage['params']['instructions'])) ?></p>
        <?php endif; ?>
        <audio controls preload="metadata" class="w-100 mb-3" src="<?= $v->url('/jobs/' . $job['id'] . '/audio/current') ?>"></audio>
        <form method="post" action="<?= $v->url('/jobs/' . $job['id'] . '/review') ?>" class="vstack gap-2">
            <?= $v->csrfField() ?>
            <textarea class="form-control" name="comment" rows="2" placeholder="<?= $v->te('review.comment') ?>"></textarea>
            <div class="d-flex gap-2">
                <button class="btn btn-success" name="decision" value="approve"><i class="bi bi-hand-thumbs-up me-1"></i><?= $v->te('review.approve') ?></button>
                <button class="btn btn-outline-danger" name="decision" value="reject"><i class="bi bi-hand-thumbs-down me-1"></i><?= $v->te('review.reject') ?></button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="aep-panel">
            <div class="aep-panel-title"><i class="bi bi-terminal me-2"></i><?= $v->te('jobs.stage_logs') ?></div>
            <div class="accordion aep-log-accordion" id="stageLogs">
                <?php foreach ($job['stages'] as $stage):
                    $ss = \AudiEdiPipe\Domain\Job\StageStatus::tryFrom((string) $stage['status']) ?? \AudiEdiPipe\Domain\Job\StageStatus::Pending;
                    $label = $stage['name'] !== '' ? $stage['name'] : $v->t('stage.' . $stage['type']);
                    $pos = (int) $stage['position'];
                    $hasAudio = ($stage['output_path'] ?? '') !== '' && $stage['output_path'] !== null && !in_array($stage['type'], ['export'], true);
                    ?>
                    <div class="accordion-item" id="stage-<?= $pos ?>">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed gap-2" type="button" data-bs-toggle="collapse" data-bs-target="#log-<?= $pos ?>">
                                <span class="aep-mini-stage aep-stage-<?= $v->e($ss->value) ?>"><i class="bi <?= $ss->icon() ?>"></i></span>
                                <span class="fw-semibold"><?= $v->e($label) ?></span>
                                <span class="small text-body-secondary ms-auto me-2"><?= $v->te('stage_status.' . $ss->value) ?></span>
                            </button>
                        </h2>
                        <div id="log-<?= $pos ?>" class="accordion-collapse collapse" data-bs-parent="#stageLogs">
                            <div class="accordion-body">
                                <?php if ($stage['actor_name']): ?><div class="small mb-2"><i class="bi bi-person me-1"></i><?= $v->e($stage['actor_name']) ?></div><?php endif; ?>
                                <?php if ($hasAudio): ?>
                                    <audio controls preload="none" class="w-100 mb-2" src="<?= $v->url('/jobs/' . $job['id'] . '/audio/' . $pos) ?>"></audio>
                                <?php endif; ?>
                                <pre class="aep-log mb-0"><?= $v->e(($stage['log'] ?? '') !== '' ? $stage['log'] : '–') ?></pre>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="aep-panel mb-3">
            <div class="aep-panel-title"><i class="bi bi-headphones me-2"></i><?= $v->te('jobs.compare') ?></div>
            <div class="aep-ab" data-ab-player>
                <div class="btn-group w-100 mb-2" role="group">
                    <button type="button" class="btn btn-sm btn-outline-secondary active" data-ab-src="<?= $v->url('/jobs/' . $job['id'] . '/audio/input') ?>">A · <?= $v->te('jobs.original') ?></button>
                    <?php if ($job['current_file']): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-ab-src="<?= $v->url('/jobs/' . $job['id'] . '/audio/current') ?>">B · <?= $v->te('jobs.processed') ?></button>
                    <?php endif; ?>
                </div>
                <audio controls preload="metadata" class="w-100" src="<?= $v->url('/jobs/' . $job['id'] . '/audio/input') ?>"></audio>
                <div class="form-text"><?= $v->te('jobs.compare_hint') ?></div>
            </div>
        </div>
        <div class="aep-panel">
            <div class="aep-panel-title"><i class="bi bi-tags me-2"></i><?= $v->te('jobs.metadata') ?></div>
            <dl class="row small mb-0">
                <?php $shown = 0; foreach ($meta as $key => $value): if (str_starts_with((string) $key, '_') || !is_scalar($value) || (string) $value === '') { continue; } ++$shown; ?>
                    <dt class="col-5 fw-normal text-body-secondary"><?= $v->te('meta.' . $key) ?></dt>
                    <dd class="col-7 text-break"><?= $v->e($value) ?></dd>
                <?php endforeach; ?>
                <?php if (isset($meta['_loudness_in'])): ?>
                    <dt class="col-5 fw-normal text-body-secondary"><?= $v->te('jobs.loudness_in') ?></dt>
                    <dd class="col-7 font-monospace"><?= $v->e(number_format((float) $meta['_loudness_in'], 1)) ?> LUFS</dd>
                <?php endif; ?>
                <?php if ($shown === 0 && !isset($meta['_loudness_in'])): ?><dd class="col-12 text-body-secondary">–</dd><?php endif; ?>
            </dl>
        </div>
    </div>
</div>
