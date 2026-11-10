<?php
/**
 * Requirements checklist of the installer. Rendered on page load and again by
 * InstallController::check() whenever the ffmpeg/ffprobe paths change.
 *
 * @var \AudiEdiPipe\Http\View $v
 * @var list<array{key: string, ok: bool, required: bool, detail: string, hint: string}> $checks
 */
?>
<?php foreach ($checks as $check): ?>
    <li class="list-group-item" data-check="<?= $v->e($check['key']) ?>">
        <div class="d-flex justify-content-between align-items-center">
            <span>
                <?php if ($check['ok']): ?>
                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                <?php elseif ($check['required']): ?>
                    <i class="bi bi-x-circle-fill text-danger me-2"></i>
                <?php else: ?>
                    <i class="bi bi-dash-circle text-warning me-2"></i>
                <?php endif; ?>
                <?= $v->te('install.check.' . $check['key']) ?>
                <?php if (!$check['required']): ?><span class="badge text-bg-secondary ms-1"><?= $v->te('common.optional') ?></span><?php endif; ?>
            </span>
            <small class="text-body-secondary text-truncate ms-2" style="max-width: 50%" title="<?= $v->e($check['detail']) ?>"><?= $v->e($check['detail']) ?></small>
        </div>
        <?php if (!$check['ok'] && $check['hint'] !== ''): ?>
            <div class="small text-danger-emphasis mt-1 ms-4"><?= $v->te($check['hint']) ?></div>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
