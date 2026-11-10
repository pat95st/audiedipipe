<?php
/** @var \AudiEdiPipe\Http\View $v */
$st = \AudiEdiPipe\Domain\Job\JobStatus::tryFrom((string) $status) ?? \AudiEdiPipe\Domain\Job\JobStatus::Queued;
?>
<span class="badge aep-status aep-status-<?= $v->e($st->value) ?>" data-job-status="<?= $v->e($st->value) ?>">
    <i class="bi <?= $st->icon() ?><?= $st === \AudiEdiPipe\Domain\Job\JobStatus::Running ? ' aep-spin' : '' ?>"></i>
    <?= $v->te('status.' . $st->value) ?>
</span>
