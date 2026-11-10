<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var array<string, mixed> $target */
/** @var list<string> $types */
/** @var array<string, list<string>> $fields */
/** @var list<string> $secrets */
/** @var list<array<string, mixed>> $sources */
/** @var string $appUrl */
$config = is_array($target['config'] ?? null) ? $target['config'] : [];
$allFields = [];
foreach ($fields as $type => $list) {
    foreach ($list as $f) {
        $allFields[$f][] = $type;
    }
}
$selects = [
    'explicit' => ['0' => 'common.no', '1' => 'common.yes'],
    'publish_immediately' => ['1' => 'common.yes', '0' => 'publish.draft'],
    'status' => ['publish' => 'publish.status.publish', 'draft' => 'publish.status.draft'],
];
$wide = ['description', 'image_url', 'website', 'public_base_url', 'storage_path'];
?>
<div class="aep-page-header">
    <div>
        <div class="small text-body-secondary"><a href="<?= $v->url('/publish') ?>"><?= $v->te('nav.publish') ?></a></div>
        <h1 class="h4 mb-0"><?= $v->e($target['id'] === null ? $v->t('publish.new') : $target['name']) ?></h1>
    </div>
    <?php if ($target['id'] !== null): ?>
        <form method="post" action="<?= $v->url('/publish/' . $target['id'] . '/delete') ?>" data-confirm="<?= $v->te('common.confirm_delete') ?>">
            <?= $v->csrfField() ?>
            <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i><?= $v->te('common.delete') ?></button>
        </form>
    <?php endif; ?>
</div>

<form method="post" action="<?= $v->url('/publish/save') ?>" class="aep-panel" autocomplete="off">
    <?= $v->csrfField() ?>
    <input type="hidden" name="id" value="<?= $v->e($target['id'] ?? '') ?>">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="name"><?= $v->te('common.name') ?></label>
            <input class="form-control" id="name" name="name" value="<?= $v->e($target['name']) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="type"><?= $v->te('publish.type') ?></label>
            <select class="form-select" id="type" name="type" data-source-type>
                <?php foreach ($types as $type): ?>
                    <option value="<?= $v->e($type) ?>"<?= $target['type'] === $type ? ' selected' : '' ?>><?= $v->te('publish.type.' . $type) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach (array_keys($fields) as $type): ?>
            <div class="col-12" data-source-field="<?= $v->e($type) ?>">
                <div class="alert alert-secondary small mb-0"><i class="bi bi-info-circle me-1"></i><?= $v->te('publish.help.' . $type) ?></div>
            </div>
        <?php endforeach; ?>
        <?php foreach ($allFields as $field => $usedBy): $value = $config[$field] ?? ''; ?>
            <div class="<?= in_array($field, $wide, true) ? 'col-12' : 'col-md-6' ?>" data-source-field="<?= $v->e(implode(' ', $usedBy)) ?>">
                <label class="form-label" for="cfg-<?= $v->e($field) ?>"><?= $v->te('publish.field.' . $field) ?></label>
                <?php if (isset($selects[$field])): ?>
                    <select class="form-select" id="cfg-<?= $v->e($field) ?>" name="config[<?= $v->e($field) ?>]">
                        <?php foreach ($selects[$field] as $optValue => $label): ?>
                            <option value="<?= $v->e($optValue) ?>"<?= (string) $value === (string) $optValue ? ' selected' : '' ?>><?= $v->te($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field === 'storage_id'): ?>
                    <select class="form-select" id="cfg-storage_id" name="config[storage_id]">
                        <option value=""><?= $v->te('publish.storage_local') ?></option>
                        <?php foreach ($sources as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"<?= (string) $value === (string) $s['id'] ? ' selected' : '' ?>><?= $v->e($s['name']) ?> (<?= $v->te('sources.type.' . $s['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field === 'description'): ?>
                    <textarea class="form-control" id="cfg-description" name="config[description]" rows="3"><?= $v->e(is_scalar($value) ? $value : '') ?></textarea>
                <?php elseif (in_array($field, $secrets, true)): ?>
                    <input class="form-control" type="password" id="cfg-<?= $v->e($field) ?>" name="config[<?= $v->e($field) ?>]" autocomplete="new-password" placeholder="<?= ($config[$field . '_set'] ?? false) ? $v->te('common.unchanged') : '' ?>">
                <?php else: ?>
                    <input class="form-control" id="cfg-<?= $v->e($field) ?>" name="config[<?= $v->e($field) ?>]" value="<?= $v->e(is_scalar($value) ? $value : '') ?>"<?= $field === 'owner_email' ? ' type="email"' : '' ?>>
                <?php endif; ?>
                <?php if ($field === 'slug' && ($config['slug'] ?? '') !== ''): ?>
                    <div class="form-text font-monospace"><?= $v->e($appUrl . '/feed/' . $config['slug']) ?></div>
                <?php elseif (in_array($field, ['public_base_url', 'storage_id', 'category'], true)): ?>
                    <div class="form-text"><?= $v->te('publish.hint.' . $field) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="col-12">
            <input type="hidden" name="enabled" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1"<?= $target['enabled'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="enabled"><?= $v->te('common.enabled') ?></label>
            </div>
        </div>
        <div class="col-12 d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="<?= $v->url('/publish') ?>"><?= $v->te('common.cancel') ?></a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button>
        </div>
    </div>
</form>
