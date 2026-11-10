<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var array<string, mixed> $editUser */
/** @var list<string> $roles */
$isNew = $editUser['id'] === null;
$isSelf = !$isNew && (int) $editUser['id'] === (int) ($user['id'] ?? 0);
?>
<div class="aep-page-header">
    <div>
        <div class="small text-body-secondary"><a href="<?= $v->url('/users') ?>"><?= $v->te('nav.users') ?></a></div>
        <h1 class="h4 mb-0"><?= $v->e($isNew ? $v->t('users.new') : $editUser['username']) ?></h1>
    </div>
    <?php if (!$isNew && !$isSelf): ?>
        <form method="post" action="<?= $v->url('/users/' . $editUser['id'] . '/delete') ?>" data-confirm="<?= $v->te('common.confirm_delete') ?>">
            <?= $v->csrfField() ?>
            <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i><?= $v->te('common.delete') ?></button>
        </form>
    <?php endif; ?>
</div>

<form method="post" action="<?= $v->url('/users/save') ?>" class="aep-panel" autocomplete="off" style="max-width: 48rem">
    <?= $v->csrfField() ?>
    <input type="hidden" name="id" value="<?= $v->e($editUser['id'] ?? '') ?>">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="username"><?= $v->te('users.username') ?></label>
            <input class="form-control" id="username" name="username" value="<?= $v->e($editUser['username']) ?>" required pattern="[a-zA-Z0-9._\-]{3,100}" autocomplete="off">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="display_name"><?= $v->te('users.display_name') ?></label>
            <input class="form-control" id="display_name" name="display_name" value="<?= $v->e($editUser['display_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="email"><?= $v->te('users.email') ?></label>
            <input class="form-control" id="email" name="email" type="email" value="<?= $v->e($editUser['email']) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="role"><?= $v->te('users.role') ?></label>
            <select class="form-select" id="role" name="role"<?= $isSelf ? ' disabled' : '' ?>>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= $v->e($role) ?>"<?= $editUser['role'] === $role ? ' selected' : '' ?>><?= $v->te('role.' . $role) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($isSelf): ?><input type="hidden" name="role" value="<?= $v->e($editUser['role']) ?>"><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="password"><?= $v->te($isNew ? 'auth.password' : 'users.new_password') ?></label>
            <input class="form-control" id="password" name="password" type="password" minlength="10" autocomplete="new-password"<?= $isNew ? ' required' : '' ?> placeholder="<?= $isNew ? '' : $v->te('common.unchanged') ?>">
            <div class="form-text"><?= $v->te('users.password_hint') ?></div>
        </div>
        <?php if (!$isNew): ?>
            <div class="col-md-6">
                <input type="hidden" name="active" value="<?= $isSelf ? '1' : '0' ?>">
                <div class="form-check form-switch mt-md-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1"<?= $editUser['active'] ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
                    <label class="form-check-label" for="active"><?= $v->te('users.active') ?></label>
                </div>
                <div class="small text-body-secondary mt-2">
                    TOTP: <?= $v->te(($editUser['totp_enabled'] ?? false) ? 'common.yes' : 'common.no') ?> ·
                    <?= $v->te('users.email_short') ?>-2FA: <?= $v->te(($editUser['email_2fa_enabled'] ?? false) ? 'common.yes' : 'common.no') ?> ·
                    Passkeys: <?= (int) ($editUser['passkey_count'] ?? 0) ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="col-12 d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="<?= $v->url('/users') ?>"><?= $v->te('common.cancel') ?></a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button>
        </div>
    </div>
</form>
