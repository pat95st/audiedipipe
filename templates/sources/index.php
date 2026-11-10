<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var list<array<string, mixed>> $sources */
$icons = ['local' => 'bi-folder2', 'ftp' => 'bi-hdd-rack', 'sftp' => 'bi-shield-lock', 'scp' => 'bi-terminal', 'webdav' => 'bi-globe2', 'nextcloud' => 'bi-cloud', 'smb' => 'bi-pc-display'];
?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('nav.sources') ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->te('sources.subtitle') ?></p>
    </div>
    <a class="btn btn-primary" href="<?= $v->url('/sources/new') ?>"><i class="bi bi-plus-lg me-1"></i><?= $v->te('sources.new') ?></a>
</div>

<?php if ($sources === []): ?>
    <div class="aep-empty">
        <i class="bi bi-hdd-network"></i>
        <p><?= $v->te('sources.empty') ?></p>
        <a class="btn btn-primary" href="<?= $v->url('/sources/new') ?>"><?= $v->te('sources.new') ?></a>
    </div>
<?php else: ?>
    <div class="aep-panel p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 aep-table">
                <thead>
                <tr>
                    <th><?= $v->te('common.name') ?></th>
                    <th><?= $v->te('sources.type') ?></th>
                    <th class="d-none d-md-table-cell"><?= $v->te('sources.path') ?></th>
                    <th class="d-none d-lg-table-cell"><?= $v->te('sources.last_scan') ?></th>
                    <th class="text-end"><?= $v->te('sources.imported') ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sources as $s): ?>
                    <tr>
                        <td>
                            <span class="aep-led me-2 <?= $s['enabled'] ? (($s['last_error'] ?? '') !== '' && $s['last_error'] !== null ? 'aep-led-red on' : 'aep-led-green on') : '' ?>"></span>
                            <a href="<?= $v->url('/sources/' . $s['id']) ?>" class="fw-semibold"><?= $v->e($s['name']) ?></a>
                            <?php if (($s['last_error'] ?? '') !== '' && $s['last_error'] !== null): ?>
                                <div class="small text-danger text-truncate" style="max-width: 28rem" title="<?= $v->e($s['last_error']) ?>"><?= $v->e($s['last_error']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><i class="bi <?= $icons[$s['type']] ?? 'bi-hdd' ?> me-1"></i><?= $v->te('sources.type.' . $s['type']) ?></td>
                        <td class="d-none d-md-table-cell font-monospace small text-truncate" style="max-width: 16rem"><?= $v->e($s['path']) ?></td>
                        <td class="d-none d-lg-table-cell small"><?= $v->date($s['last_scan_at'] ?? null) ?></td>
                        <td class="text-end"><?= (int) $s['imported_count'] ?></td>
                        <td class="text-end text-nowrap">
                            <form method="post" action="<?= $v->url('/sources/' . $s['id'] . '/scan') ?>" class="d-inline">
                                <?= $v->csrfField() ?>
                                <button class="btn btn-sm btn-outline-secondary" title="<?= $v->te('sources.scan_now') ?>"><i class="bi bi-arrow-repeat"></i><span class="d-none d-xl-inline ms-1"><?= $v->te('sources.scan_now') ?></span></button>
                            </form>
                            <a class="btn btn-sm btn-outline-primary" href="<?= $v->url('/sources/' . $s['id']) ?>" aria-label="<?= $v->te('common.edit') ?>"><i class="bi bi-pencil"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
