<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var list<array<string, mixed>> $stages */
?>
<div class="aep-mini-pipeline" aria-label="<?= $v->te('jobs.stages') ?>">
    <?php foreach ($stages as $stage):
        $ss = \AudiEdiPipe\Domain\Job\StageStatus::tryFrom((string) $stage['status']) ?? \AudiEdiPipe\Domain\Job\StageStatus::Pending;
        $label = ($stage['name'] ?? '') !== '' ? (string) $stage['name'] : $v->t('stage.' . $stage['type']);
        ?>
        <span class="aep-mini-stage aep-stage-<?= $v->e($ss->value) ?>" data-stage-position="<?= (int) $stage['position'] ?>" title="<?= $v->e($label . ' – ' . $v->t('stage_status.' . $ss->value)) ?>">
            <i class="bi <?= $ss->icon() ?>"></i>
        </span>
    <?php endforeach; ?>
</div>
