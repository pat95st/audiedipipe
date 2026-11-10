<?php
/** @var \AudiEdiPipe\Http\View $v */
$byStatus = $stats['by_status'];
$running = ($byStatus['running'] ?? 0) + ($byStatus['queued'] ?? 0);
$waiting = ($byStatus['waiting_manual'] ?? 0) + ($byStatus['waiting_review'] ?? 0);
$max = max(1, ...array_values($stats['last_7_days']));
?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('dashboard.hello', ['name' => (string) ($user['display_name'] ?: $user['username'])]) ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->te('dashboard.subtitle') ?></p>
    </div>
    <?php if ($v->can('jobs.upload')): ?>
        <a class="btn btn-primary" href="<?= $v->url('/upload') ?>"><i class="bi bi-cloud-arrow-up me-1"></i><?= $v->te('nav.upload') ?></a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['dashboard.total_jobs', $stats['total'], 'bi-collection-play', ''],
        ['dashboard.active', $running, 'bi-arrow-repeat', 'aep-led-blue'],
        ['dashboard.waiting', $waiting, 'bi-hourglass-split', 'aep-led-amber'],
        ['dashboard.failed', ($byStatus['failed'] ?? 0) + ($byStatus['rejected'] ?? 0), 'bi-exclamation-triangle', 'aep-led-red'],
    ] as [$label, $value, $icon, $led]): ?>
        <div class="col-6 col-xl-3">
            <div class="aep-panel aep-stat">
                <div class="d-flex justify-content-between align-items-start">
                    <span class="aep-panel-label"><?= $v->te($label) ?></span>
                    <i class="bi <?= $icon ?> text-body-secondary"></i>
                </div>
                <div class="aep-lcd mt-2">
                    <?php if ($led !== ''): ?><span class="aep-led <?= $led ?><?= $value > 0 ? ' on' : '' ?>"></span><?php endif; ?>
                    <span class="aep-lcd-text"><?= (int) $value ?></span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <?php if ($todo !== []): ?>
            <div class="aep-panel mb-3">
                <div class="aep-panel-title"><i class="bi bi-inbox me-2"></i><?= $v->te('dashboard.todo') ?></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($todo as $job): ?>
                        <a class="list-group-item list-group-item-action d-flex align-items-center gap-3" href="<?= $v->url($job['status'] === 'waiting_manual' ? '/jobs/' . $job['id'] . '/edit' : '/jobs/' . $job['id']) ?>">
                            <?= $v->partial('partials/job_status', ['status' => $job['status']]) ?>
                            <div class="flex-grow-1 text-truncate">
                                <div class="fw-semibold text-truncate"><?= $v->e($job['original_name']) ?></div>
                                <div class="small text-body-secondary"><?= $v->e($job['pipeline_name']) ?> · #<?= (int) $job['id'] ?></div>
                            </div>
                            <i class="bi bi-chevron-right text-body-secondary"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="aep-panel">
            <div class="aep-panel-title d-flex justify-content-between">
                <span><i class="bi bi-clock-history me-2"></i><?= $v->te('dashboard.recent') ?></span>
                <a class="small" href="<?= $v->url('/jobs') ?>"><?= $v->te('common.show_all') ?></a>
            </div>
            <?php if ($recent === []): ?>
                <div class="aep-empty py-4">
                    <i class="bi bi-music-note-list display-6 text-body-secondary"></i>
                    <p class="mt-2 mb-0 text-body-secondary"><?= $v->te('dashboard.no_jobs') ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 aep-table" data-job-poll>
                        <tbody>
                        <?php foreach ($recent as $job): ?>
                            <tr data-job-id="<?= (int) $job['id'] ?>">
                                <td class="text-nowrap"><?= $v->partial('partials/job_status', ['status' => $job['status']]) ?></td>
                                <td class="text-truncate" style="max-width: 280px">
                                    <a href="<?= $v->url('/jobs/' . $job['id']) ?>" class="fw-semibold text-decoration-none"><?= $v->e($job['original_name']) ?></a>
                                    <div class="small text-body-secondary"><?= $v->e($job['pipeline_name']) ?></div>
                                </td>
                                <td class="d-none d-md-table-cell"><?= $v->partial('partials/mini_pipeline', ['stages' => $job['stages']]) ?></td>
                                <td class="small text-body-secondary text-nowrap d-none d-sm-table-cell"><?= $v->date($job['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="aep-panel mb-3">
            <div class="aep-panel-title"><i class="bi bi-bar-chart me-2"></i><?= $v->te('dashboard.last_7_days') ?></div>
            <div class="aep-meter-bars" role="img" aria-label="<?= $v->te('dashboard.last_7_days') ?>">
                <?php foreach ($stats['last_7_days'] as $day => $count): ?>
                    <div class="aep-meter-col" title="<?= $v->e($day . ': ' . $count) ?>">
                        <div class="aep-meter-track"><div class="aep-meter-fill" style="height: <?= round($count / $max * 100) ?>%"></div></div>
                        <span class="aep-meter-label"><?= $v->e(substr($day, 8, 2)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="aep-panel">
            <div class="aep-panel-title"><i class="bi bi-info-circle me-2"></i><?= $v->te('dashboard.system') ?></div>
            <dl class="row mb-0 small">
                <dt class="col-7 fw-normal text-body-secondary"><?= $v->te('nav.pipelines') ?></dt><dd class="col-5 text-end font-monospace"><?= (int) $stats['pipelines'] ?></dd>
                <dt class="col-7 fw-normal text-body-secondary"><?= $v->te('dashboard.watched_sources') ?></dt><dd class="col-5 text-end font-monospace"><?= (int) $stats['sources'] ?></dd>
                <dt class="col-7 fw-normal text-body-secondary"><?= $v->te('status.success') ?></dt><dd class="col-5 text-end font-monospace"><?= (int) ($byStatus['success'] ?? 0) ?></dd>
            </dl>
        </div>
    </div>
</div>
