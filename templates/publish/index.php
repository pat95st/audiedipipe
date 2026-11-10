<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var list<array<string, mixed>> $targets */
/** @var string $appUrl */
$icons = ['rss' => 'bi-rss', 'buzzsprout' => 'bi-broadcast', 'podbean' => 'bi-mic', 'spreaker' => 'bi-soundwave'];
?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('nav.publish') ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->te('publish.subtitle') ?></p>
    </div>
    <a class="btn btn-primary" href="<?= $v->url('/publish/new') ?>"><i class="bi bi-plus-lg me-1"></i><?= $v->te('publish.new') ?></a>
</div>

<?php if ($targets === []): ?>
    <div class="aep-empty">
        <i class="bi bi-broadcast-pin"></i>
        <p><?= $v->te('publish.empty') ?></p>
        <a class="btn btn-primary" href="<?= $v->url('/publish/new') ?>"><?= $v->te('publish.new') ?></a>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($targets as $t): ?>
            <div class="col-md-6 col-xxl-4">
                <div class="aep-panel h-100">
                    <div class="d-flex align-items-start gap-3">
                        <div class="aep-target-icon"><i class="bi <?= $icons[$t['type']] ?? 'bi-broadcast' ?>"></i></div>
                        <div class="flex-grow-1 text-truncate">
                            <a class="h6 mb-0 d-block text-truncate" href="<?= $v->url('/publish/' . $t['id']) ?>"><?= $v->e($t['name']) ?></a>
                            <div class="small text-body-secondary"><?= $v->te('publish.type.' . $t['type']) ?></div>
                        </div>
                        <span class="aep-led <?= $t['enabled'] ? 'aep-led-green on' : '' ?>"></span>
                    </div>
                    <?php if ($t['type'] === 'rss' && ($t['slug'] ?? '') !== ''): $feed = $appUrl . '/feed/' . $t['slug']; ?>
                        <div class="input-group input-group-sm mt-3">
                            <span class="input-group-text"><i class="bi bi-rss"></i></span>
                            <input class="form-control font-monospace" value="<?= $v->e($feed) ?>" readonly aria-label="<?= $v->te('publish.feed_url') ?>">
                            <button class="btn btn-outline-secondary" type="button" data-copy="<?= $v->e($feed) ?>" aria-label="<?= $v->te('common.copy') ?>"><i class="bi bi-clipboard"></i></button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<p class="small text-body-secondary mt-4"><i class="bi bi-info-circle me-1"></i><?= $v->te('publish.platform_hint') ?></p>
