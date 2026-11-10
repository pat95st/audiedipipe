<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var list<array<string, mixed>> $pipelines */
?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('nav.pipelines') ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->te('pipelines.subtitle') ?></p>
    </div>
    <a class="btn btn-primary" href="<?= $v->url('/pipelines/new') ?>"><i class="bi bi-plus-lg me-1"></i><?= $v->te('pipelines.new') ?></a>
</div>

<?php if ($pipelines === []): ?>
    <div class="aep-empty">
        <i class="bi bi-bezier2"></i>
        <p><?= $v->te('pipelines.empty') ?></p>
        <a class="btn btn-primary" href="<?= $v->url('/pipelines/new') ?>"><?= $v->te('pipelines.new') ?></a>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($pipelines as $p): ?>
            <div class="col-md-6 col-xxl-4">
                <div class="aep-panel h-100 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="text-truncate">
                            <a class="h5 mb-0 text-decoration-none text-body d-block text-truncate" href="<?= $v->url('/pipelines/' . $p['id']) ?>"><?= $v->e($p['name']) ?></a>
                            <div class="small text-body-secondary text-truncate"><?= $v->e($p['description'] ?: '–') ?></div>
                        </div>
                        <span class="aep-led <?= $p['enabled'] ? 'aep-led-green on' : '' ?>" title="<?= $v->te($p['enabled'] ? 'common.enabled' : 'common.disabled') ?>"></span>
                    </div>
                    <div class="aep-stage-strip my-3">
                        <?php foreach ($p['stages'] as $stage): if (!is_array($stage)) { continue; } ?>
                            <span class="aep-stage-chip aep-type-<?= $v->e($stage['type']) ?><?= ($stage['enabled'] ?? true) ? '' : ' disabled' ?>" title="<?= $v->e(($stage['name'] ?? '') !== '' ? $stage['name'] : $v->t('stage.' . $stage['type'])) ?>"></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="small text-body-secondary d-flex flex-wrap gap-3 mb-3">
                        <span><i class="bi bi-layers me-1"></i><?= count($p['stages']) ?> <?= $v->te('pipelines.stages') ?></span>
                        <span><i class="bi bi-collection-play me-1"></i><?= (int) $p['job_count'] ?> <?= $v->te('nav.jobs') ?></span>
                        <?php if (($p['source_name'] ?? null) !== null): ?>
                            <span><i class="bi bi-hdd-network me-1"></i><?= $v->e($p['source_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 mt-auto">
                        <a class="btn btn-sm btn-outline-primary" href="<?= $v->url('/pipelines/' . $p['id']) ?>"><i class="bi bi-pencil me-1"></i><?= $v->te('common.edit') ?></a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= $v->url('/upload?pipeline=' . $p['id']) ?>"><i class="bi bi-cloud-arrow-up me-1"></i><?= $v->te('pipelines.test_upload') ?></a>
                        <form method="post" action="<?= $v->url('/pipelines/' . $p['id'] . '/duplicate') ?>" class="ms-auto">
                            <?= $v->csrfField() ?>
                            <button class="btn btn-sm btn-link text-body-secondary" title="<?= $v->te('pipelines.duplicate') ?>" aria-label="<?= $v->te('pipelines.duplicate') ?>"><i class="bi bi-copy"></i></button>
                        </form>
                        <form method="post" action="<?= $v->url('/pipelines/' . $p['id'] . '/delete') ?>" data-confirm="<?= $v->te('common.confirm_delete') ?>">
                            <?= $v->csrfField() ?>
                            <button class="btn btn-sm btn-link text-danger" title="<?= $v->te('common.delete') ?>" aria-label="<?= $v->te('common.delete') ?>"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
