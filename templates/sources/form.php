<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var array<string, mixed> $source */
/** @var list<string> $types */
/** @var array<string, list<string>> $fields */
/** @var list<string> $secrets */
$config = is_array($source['config'] ?? null) ? $source['config'] : [];
$filter = is_array($source['filter'] ?? null) ? $source['filter'] : [];
$allFields = [];
foreach ($fields as $type => $list) {
    foreach ($list as $f) {
        $allFields[$f][] = $type;
    }
}
$checkboxFields = ['ssl', 'passive', 'verify_tls'];
$textareaFields = ['private_key', 'public_key'];
$defaults = ['passive' => true, 'verify_tls' => true];
?>
<div class="aep-page-header">
    <div>
        <div class="small text-body-secondary"><a href="<?= $v->url('/sources') ?>"><?= $v->te('nav.sources') ?></a></div>
        <h1 class="h4 mb-0"><?= $v->e($source['id'] === null ? $v->t('sources.new') : $source['name']) ?></h1>
    </div>
    <?php if ($source['id'] !== null): ?>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary" type="button" data-source-test="<?= $v->url('/sources/' . $source['id'] . '/test') ?>"><i class="bi bi-plug me-1"></i><?= $v->te('sources.test') ?></button>
            <form method="post" action="<?= $v->url('/sources/' . $source['id'] . '/delete') ?>" data-confirm="<?= $v->te('common.confirm_delete') ?>">
                <?= $v->csrfField() ?>
                <button class="btn btn-outline-danger" aria-label="<?= $v->te('common.delete') ?>"><i class="bi bi-trash"></i></button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="aep-panel mb-3 d-none" data-source-test-result></div>

<form method="post" action="<?= $v->url('/sources/save') ?>" class="row g-3" data-source-form autocomplete="off">
    <?= $v->csrfField() ?>
    <input type="hidden" name="id" value="<?= $v->e($source['id'] ?? '') ?>">
    <div class="col-lg-6">
        <div class="aep-panel h-100">
            <div class="aep-panel-title"><i class="bi bi-hdd-network me-2"></i><?= $v->te('sources.connection') ?></div>
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label" for="name"><?= $v->te('common.name') ?></label>
                    <input class="form-control" id="name" name="name" value="<?= $v->e($source['name']) ?>" required maxlength="190">
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="type"><?= $v->te('sources.type') ?></label>
                    <select class="form-select" id="type" name="type" data-source-type>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= $v->e($type) ?>"<?= $source['type'] === $type ? ' selected' : '' ?>><?= $v->te('sources.type.' . $type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php foreach ($allFields as $field => $usedBy): $value = $config[$field] ?? ($defaults[$field] ?? ''); ?>
                    <div class="<?= in_array($field, $textareaFields, true) || $field === 'url' ? 'col-12' : 'col-md-6' ?>" data-source-field="<?= $v->e(implode(' ', $usedBy)) ?>">
                        <?php if (in_array($field, $checkboxFields, true)): ?>
                            <input type="hidden" name="config[<?= $v->e($field) ?>]" value="0">
                            <div class="form-check form-switch mt-md-4">
                                <input class="form-check-input" type="checkbox" role="switch" id="cfg-<?= $v->e($field) ?>" name="config[<?= $v->e($field) ?>]" value="1"<?= in_array($value, [true, 1, '1', 'on'], true) ? ' checked' : '' ?>>
                                <label class="form-check-label" for="cfg-<?= $v->e($field) ?>"><?= $v->te('sources.field.' . $field) ?></label>
                            </div>
                        <?php else: ?>
                            <label class="form-label" for="cfg-<?= $v->e($field) ?>"><?= $v->te('sources.field.' . $field) ?></label>
                            <?php if (in_array($field, $secrets, true)): ?>
                                <?php if ($field === 'private_key'): ?>
                                    <textarea class="form-control font-monospace small" rows="3" id="cfg-<?= $v->e($field) ?>" name="config[<?= $v->e($field) ?>]" placeholder="<?= ($config[$field . '_set'] ?? false) ? $v->te('common.unchanged') : '-----BEGIN OPENSSH PRIVATE KEY-----' ?>"></textarea>
                                <?php else: ?>
                                    <input class="form-control" type="password" id="cfg-<?= $v->e($field) ?>" name="config[<?= $v->e($field) ?>]" autocomplete="new-password" placeholder="<?= ($config[$field . '_set'] ?? false) ? $v->te('common.unchanged') : '' ?>">
                                <?php endif; ?>
                            <?php elseif (in_array($field, $textareaFields, true)): ?>
                                <textarea class="form-control font-monospace small" rows="2" id="cfg-<?= $v->e($field) ?>" name="config[<?= $v->e($field) ?>]"><?= $v->e(is_scalar($value) ? $value : '') ?></textarea>
                            <?php else: ?>
                                <input class="form-control<?= in_array($field, ['url', 'host'], true) ? ' font-monospace' : '' ?>" id="cfg-<?= $v->e($field) ?>" name="config[<?= $v->e($field) ?>]" value="<?= $v->e(is_scalar($value) ? $value : '') ?>"<?= $field === 'port' ? ' type="number" min="1" max="65535"' : '' ?>>
                            <?php endif; ?>
                            <?php if (in_array($field, ['url', 'public_key', 'share'], true)): ?>
                                <div class="form-text" data-hint-for="<?= $v->e($field) ?>"><?= $v->te('sources.hint.' . $field) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="col-12">
                    <label class="form-label" for="path"><?= $v->te('sources.path') ?></label>
                    <input class="form-control font-monospace" id="path" name="path" value="<?= $v->e($source['path']) ?>" required>
                    <div class="form-text"><?= $v->te('sources.path_hint') ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="aep-panel mb-3">
            <div class="aep-panel-title"><i class="bi bi-funnel me-2"></i><?= $v->te('sources.filter') ?></div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="f-ext"><?= $v->te('sources.filter.extensions') ?></label>
                    <input class="form-control font-monospace" id="f-ext" name="filter[extensions]" value="<?= $v->e($filter['extensions'] ?? '') ?>" placeholder="wav,mp3,flac">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f-include"><?= $v->te('sources.filter.include') ?></label>
                    <input class="form-control font-monospace" id="f-include" name="filter[include]" value="<?= $v->e($filter['include'] ?? '') ?>" placeholder="episode_*, *_final.*">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f-exclude"><?= $v->te('sources.filter.exclude') ?></label>
                    <input class="form-control font-monospace" id="f-exclude" name="filter[exclude]" value="<?= $v->e($filter['exclude'] ?? '') ?>" placeholder="*.tmp, test_*">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f-regex"><?= $v->te('sources.filter.regex') ?></label>
                    <input class="form-control font-monospace" id="f-regex" name="filter[regex]" value="<?= $v->e($filter['regex'] ?? '') ?>" placeholder="^\d{4}-\d{2}-\d{2}_">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="f-min"><?= $v->te('sources.filter.min_size_kb') ?></label>
                    <input class="form-control" type="number" min="0" id="f-min" name="filter[min_size_kb]" value="<?= $v->e($filter['min_size_kb'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="f-max"><?= $v->te('sources.filter.max_size_mb') ?></label>
                    <input class="form-control" type="number" min="0" id="f-max" name="filter[max_size_mb]" value="<?= $v->e($filter['max_size_mb'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="f-age"><?= $v->te('sources.filter.min_age_seconds') ?></label>
                    <input class="form-control" type="number" min="0" id="f-age" name="filter[min_age_seconds]" value="<?= $v->e($filter['min_age_seconds'] ?? 60) ?>">
                </div>
                <div class="col-12">
                    <input type="hidden" name="filter[recursive]" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="f-rec" name="filter[recursive]" value="1"<?= ($filter['recursive'] ?? false) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="f-rec"><?= $v->te('sources.filter.recursive') ?></label>
                    </div>
                </div>
            </div>
        </div>
        <div class="aep-panel">
            <div class="aep-panel-title"><i class="bi bi-clock-history me-2"></i><?= $v->te('sources.schedule') ?></div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="poll"><?= $v->te('sources.poll_interval') ?></label>
                    <select class="form-select" id="poll" name="poll_interval">
                        <?php foreach ([60, 300, 900, 1800, 3600, 21600, 86400] as $sec): ?>
                            <option value="<?= $sec ?>"<?= (int) $source['poll_interval'] === $sec ? ' selected' : '' ?>><?= $sec < 3600 ? ($sec / 60) . ' min' : ($sec / 3600) . ' h' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="after"><?= $v->te('sources.after_import') ?></label>
                    <select class="form-select" id="after" name="after_import">
                        <option value="keep"<?= $source['after_import'] === 'keep' ? ' selected' : '' ?>><?= $v->te('sources.after_import.keep') ?></option>
                        <option value="delete"<?= $source['after_import'] === 'delete' ? ' selected' : '' ?>><?= $v->te('sources.after_import.delete') ?></option>
                    </select>
                </div>
                <div class="col-12">
                    <input type="hidden" name="enabled" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1"<?= $source['enabled'] ? ' checked' : '' ?>>
                        <label class="form-check-label" for="enabled"><?= $v->te('sources.enabled_hint') ?></label>
                    </div>
                </div>
                <div class="col-12 small text-body-secondary"><i class="bi bi-lightbulb me-1"></i><?= $v->te('sources.pipeline_hint') ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 d-flex justify-content-end gap-2">
        <a class="btn btn-outline-secondary" href="<?= $v->url('/sources') ?>"><?= $v->te('common.cancel') ?></a>
        <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button>
    </div>
</form>
