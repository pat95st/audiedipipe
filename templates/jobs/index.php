<?php
/** @var \AudiEdiPipe\Http\View $v */
$pages = (int) ceil($total / $perPage);
$statuses = ['queued', 'running', 'waiting_manual', 'waiting_review', 'success', 'failed', 'rejected', 'canceled'];
$qs = static fn (array $extra) => '?' . http_build_query(array_filter(['status' => $status, 'pipeline' => $pipelineId] + $extra, static fn ($x) => $x !== null && $x !== '' && $x !== 0));
?>
<div class="aep-page-header">
    <h1 class="h3 mb-0"><?= $v->te('nav.jobs') ?></h1>
    <?php if ($v->can('jobs.upload')): ?>
        <a class="btn btn-primary" href="<?= $v->url('/upload') ?>"><i class="bi bi-cloud-arrow-up me-1"></i><?= $v->te('nav.upload') ?></a>
    <?php endif; ?>
</div>

<form class="aep-panel d-flex flex-wrap gap-2 align-items-end mb-3" method="get">
    <div>
        <label class="form-label small mb-1" for="f-status"><?= $v->te('jobs.status') ?></label>
        <select class="form-select form-select-sm" id="f-status" name="status" data-autosubmit>
            <option value=""><?= $v->te('common.all') ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= $v->te('status.' . $s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="form-label small mb-1" for="f-pipeline"><?= $v->te('jobs.pipeline') ?></label>
        <select class="form-select form-select-sm" id="f-pipeline" name="pipeline" data-autosubmit>
            <option value=""><?= $v->te('common.all') ?></option>
            <?php foreach ($pipelines as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= $pipelineId === (int) $p['id'] ? ' selected' : '' ?>><?= $v->e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <noscript><button class="btn btn-sm btn-secondary"><?= $v->te('common.filter') ?></button></noscript>
    <div class="ms-auto small text-body-secondary"><?= $v->te('jobs.count', ['count' => $total]) ?></div>
</form>

<div class="aep-panel p-0">
    <?php if ($jobs === []): ?>
        <div class="aep-empty py-5"><i class="bi bi-inbox display-6 text-body-secondary"></i><p class="mt-2 text-body-secondary"><?= $v->te('jobs.none') ?></p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 aep-table" data-job-poll>
                <thead>
                <tr>
                    <th><?= $v->te('jobs.status') ?></th>
                    <th>#</th>
                    <th><?= $v->te('jobs.file') ?></th>
                    <th class="d-none d-lg-table-cell"><?= $v->te('jobs.stages') ?></th>
                    <th class="d-none d-md-table-cell"><?= $v->te('jobs.created') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr data-job-id="<?= (int) $job['id'] ?>">
                        <td class="text-nowrap"><?= $v->partial('partials/job_status', ['status' => $job['status']]) ?></td>
                        <td class="font-monospace small"><a href="<?= $v->url('/jobs/' . $job['id']) ?>">#<?= (int) $job['id'] ?></a></td>
                        <td class="text-truncate" style="max-width: 320px">
                            <a href="<?= $v->url('/jobs/' . $job['id']) ?>" class="fw-semibold text-decoration-none"><?= $v->e($job['original_name']) ?></a>
                            <div class="small text-body-secondary">
                                <i class="bi <?= $job['origin'] === 'source' ? 'bi-hdd-network' : 'bi-upload' ?> me-1"></i><?= $v->e($job['pipeline_name']) ?>
                            </div>
                        </td>
                        <td class="d-none d-lg-table-cell"><?= $v->partial('partials/mini_pipeline', ['stages' => $job['stages']]) ?></td>
                        <td class="small text-body-secondary text-nowrap d-none d-md-table-cell"><?= $v->date($job['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
    <nav class="mt-3" aria-label="Pagination">
        <ul class="pagination pagination-sm justify-content-center">
            <?php for ($i = 1; $i <= $pages; ++$i): ?>
                <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= $v->e($qs(['page' => $i])) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
