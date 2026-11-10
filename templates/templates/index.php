<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var list<array<string, mixed>> $templates */
/** @var list<string> $fields */
/** @var list<string> $placeholders */
$allFields = array_merge($fields, ['cover']);
$longFields = ['description', 'comment'];
$blank = ['id' => null, 'name' => '', 'fields' => []];
?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('nav.templates') ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->te('templates.subtitle') ?></p>
    </div>
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#tpl-new" aria-expanded="false"><i class="bi bi-plus-lg me-1"></i><?= $v->te('templates.new') ?></button>
</div>

<div class="aep-panel mb-3 small">
    <div class="fw-semibold mb-1"><i class="bi bi-braces me-1"></i><?= $v->te('templates.placeholders') ?></div>
    <div class="d-flex flex-wrap gap-1">
        <?php foreach ($placeholders as $ph): ?>
            <code class="aep-placeholder" title="<?= $v->te('templates.ph.' . $ph) ?>">{<?= $v->e($ph) ?><?= $ph === 'counter' ? '[:3]' : '' ?>}</code>
        <?php endforeach; ?>
    </div>
    <div class="text-body-secondary mt-1"><?= $v->te('templates.placeholders_hint') ?></div>
</div>

<?php foreach (array_merge([$blank], $templates) as $tpl): $id = $tpl['id']; $key = $id === null ? 'new' : (string) $id; ?>
    <div class="aep-panel mb-3<?= $id === null ? ' collapse' : '' ?>" id="tpl-<?= $v->e($key) ?>">
        <form method="post" action="<?= $v->url('/templates/save') ?>" class="row g-2">
            <?= $v->csrfField() ?>
            <input type="hidden" name="id" value="<?= $v->e($id ?? '') ?>">
            <div class="col-12 d-flex gap-2 align-items-end mb-2">
                <div class="flex-grow-1">
                    <label class="form-label small mb-1" for="tpl-name-<?= $v->e($key) ?>"><?= $v->te('common.name') ?></label>
                    <input class="form-control fw-semibold" id="tpl-name-<?= $v->e($key) ?>" name="name" value="<?= $v->e($tpl['name']) ?>" required>
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button>
            </div>
            <?php foreach ($allFields as $field): $value = (string) ($tpl['fields'][$field] ?? ''); ?>
                <div class="<?= in_array($field, $longFields, true) ? 'col-12' : 'col-md-6 col-xl-4' ?>">
                    <label class="form-label small mb-1" for="tpl-<?= $v->e($key . '-' . $field) ?>"><?= $v->te('meta.' . $field) ?></label>
                    <?php if ($field === 'cover'): ?>
                        <div class="input-group input-group-sm" data-cover-upload="<?= $v->url('/templates/cover') ?>">
                            <input class="form-control font-monospace" id="tpl-<?= $v->e($key . '-cover') ?>" name="fields[cover]" value="<?= $v->e($value) ?>" placeholder="uploads/covers/…">
                            <label class="btn btn-outline-secondary mb-0"><i class="bi bi-image"></i><input type="file" class="visually-hidden" accept="image/jpeg,image/png"></label>
                        </div>
                    <?php elseif (in_array($field, $longFields, true)): ?>
                        <textarea class="form-control form-control-sm" rows="2" id="tpl-<?= $v->e($key . '-' . $field) ?>" name="fields[<?= $v->e($field) ?>]"><?= $v->e($value) ?></textarea>
                    <?php else: ?>
                        <input class="form-control form-control-sm" id="tpl-<?= $v->e($key . '-' . $field) ?>" name="fields[<?= $v->e($field) ?>]" value="<?= $v->e($value) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </form>
        <?php if ($id !== null): ?>
            <form method="post" action="<?= $v->url('/templates/' . $id . '/delete') ?>" class="text-end mt-2" data-confirm="<?= $v->te('common.confirm_delete') ?>">
                <?= $v->csrfField() ?>
                <button class="btn btn-sm btn-link text-danger"><i class="bi bi-trash me-1"></i><?= $v->te('common.delete') ?></button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
