<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var array<string, mixed> $job */
/** @var array<string, mixed>|null $stage */
/** @var list<string> $fields */
/** @var list<array<string, mixed>> $templates */
$v->share('scripts', ['js/cutter.js']);
$meta = $job['metadata'];
$longFields = ['description', 'comment'];
$cutterData = [
    'jobId' => (int) $job['id'],
    'audio' => $v->url('/jobs/' . $job['id'] . '/audio/current'),
    'peaks' => $v->url('/jobs/' . $job['id'] . '/peaks/current'),
    'submit' => $v->url('/jobs/' . $job['id'] . '/edit'),
    'templates' => array_map(static fn (array $t): array => ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'fields' => $t['fields']], $templates),
];
?>
<script type="application/json" id="aep-cutter-data"><?= $v->json($cutterData) ?></script>

<div class="aep-page-header">
    <div class="text-truncate">
        <div class="small text-body-secondary"><a href="<?= $v->url('/jobs/' . $job['id']) ?>">#<?= (int) $job['id'] ?></a> / <?= $v->te('edit.title') ?></div>
        <h1 class="h4 mb-0 text-truncate"><i class="bi bi-scissors me-2"></i><?= $v->e($job['original_name']) ?></h1>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= $v->url('/jobs/' . $job['id']) ?>"><?= $v->te('common.cancel') ?></a>
        <button class="btn btn-success" type="button" data-cutter-submit><i class="bi bi-check2-circle me-1"></i><?= $v->te('edit.submit') ?></button>
    </div>
</div>

<?php if (($stage['params']['instructions'] ?? '') !== ''): ?>
    <div class="alert alert-info d-flex gap-2"><i class="bi bi-info-circle-fill"></i><div><?= nl2br($v->e($stage['params']['instructions'])) ?></div></div>
<?php endif; ?>
<div class="alert alert-danger d-none" data-cutter-error role="alert"></div>

<div class="aep-panel mb-3 aep-cutter" data-cutter>
    <div class="aep-transport mb-2">
        <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="play" title="<?= $v->te('edit.play') ?> (Space)" aria-label="<?= $v->te('edit.play') ?>"><i class="bi bi-play-fill"></i></button>
        <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="stop" title="<?= $v->te('edit.stop') ?>" aria-label="<?= $v->te('edit.stop') ?>"><i class="bi bi-stop-fill"></i></button>
        <div class="aep-lcd aep-lcd-time" aria-live="off"><span class="aep-lcd-text" data-cutter-time>00:00.000</span><span class="aep-lcd-sub" data-cutter-duration>/ 00:00.000</span></div>
        <div class="vr mx-1 d-none d-md-block"></div>
        <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="mark-in" title="<?= $v->te('edit.mark_in') ?> (I)"><i class="bi bi-chevron-bar-left"></i> In</button>
        <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="mark-out" title="<?= $v->te('edit.mark_out') ?> (O)">Out <i class="bi bi-chevron-bar-right"></i></button>
        <button class="btn btn-sm aep-hw-btn aep-hw-red" type="button" data-cutter-action="cut" title="<?= $v->te('edit.cut_selection') ?> (Del)"><i class="bi bi-scissors"></i> <?= $v->te('edit.cut') ?></button>
        <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="trim-start" title="<?= $v->te('edit.trim_start') ?>"><i class="bi bi-skip-start"></i></button>
        <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="trim-end" title="<?= $v->te('edit.trim_end') ?>"><i class="bi bi-skip-end"></i></button>
        <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="undo" title="<?= $v->te('edit.undo') ?> (Ctrl+Z)"><i class="bi bi-arrow-counterclockwise"></i></button>
        <div class="ms-auto d-flex align-items-center gap-1">
            <div class="form-check form-switch mb-0 me-2 small">
                <input class="form-check-input" type="checkbox" role="switch" id="skipCuts" data-cutter-skip checked>
                <label class="form-check-label" for="skipCuts"><?= $v->te('edit.skip_cuts') ?></label>
            </div>
            <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="zoom-out" aria-label="Zoom out"><i class="bi bi-zoom-out"></i></button>
            <button class="btn btn-sm aep-hw-btn" type="button" data-cutter-action="zoom-in" aria-label="Zoom in"><i class="bi bi-zoom-in"></i></button>
        </div>
    </div>
    <div class="aep-wave-wrap" data-cutter-scroll>
        <canvas class="aep-wave" data-cutter-canvas height="180"></canvas>
        <div class="aep-wave-loading" data-cutter-loading><span class="spinner-border spinner-border-sm me-2"></span><?= $v->te('edit.loading_waveform') ?></div>
    </div>
    <canvas class="aep-wave-overview mt-1" data-cutter-overview height="36"></canvas>
    <div class="form-text"><?= $v->te('edit.help') ?></div>
    <audio data-cutter-audio preload="auto" src="<?= $v->e($cutterData['audio']) ?>"></audio>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="aep-panel h-100">
            <div class="aep-panel-title"><i class="bi bi-scissors me-2"></i><?= $v->te('edit.cuts') ?></div>
            <ul class="list-group list-group-flush small" data-cutter-list></ul>
            <div class="small text-body-secondary mt-2" data-cutter-summary></div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="aep-panel">
            <div class="aep-panel-title d-flex align-items-center">
                <span><i class="bi bi-tags me-2"></i><?= $v->te('jobs.metadata') ?></span>
                <?php if ($templates !== []): ?>
                    <div class="ms-auto d-flex gap-2 align-items-center">
                        <select class="form-select form-select-sm" data-cutter-template aria-label="<?= $v->te('edit.apply_template') ?>">
                            <option value=""><?= $v->te('edit.apply_template') ?>…</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"><?= $v->e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <form class="row g-2" data-cutter-meta>
                <?php foreach ($fields as $field): ?>
                    <div class="<?= in_array($field, $longFields, true) ? 'col-12' : 'col-md-6' ?>">
                        <label class="form-label small mb-1" for="meta-<?= $v->e($field) ?>"><?= $v->te('meta.' . $field) ?></label>
                        <?php if (in_array($field, $longFields, true)): ?>
                            <textarea class="form-control form-control-sm" rows="3" id="meta-<?= $v->e($field) ?>" name="<?= $v->e($field) ?>"><?= $v->e($meta[$field] ?? '') ?></textarea>
                        <?php else: ?>
                            <input class="form-control form-control-sm" id="meta-<?= $v->e($field) ?>" name="<?= $v->e($field) ?>" value="<?= $v->e($meta[$field] ?? '') ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </form>
        </div>
    </div>
</div>
