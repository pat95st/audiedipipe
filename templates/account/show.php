<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var list<array<string, mixed>> $passkeys */
/** @var array<string, string> $languages */
/** @var string $totpSecret */
/** @var string $totpUri */
$v->share('scripts', ['vendor/qrcode/qrcode.js', 'js/account.js']);
$u = $user;
?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('account.title') ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->e($u['username']) ?> · <?= $v->te('role.' . $u['role']) ?></p>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <form method="post" action="<?= $v->url('/account/profile') ?>" class="aep-panel mb-3">
            <?= $v->csrfField() ?>
            <div class="aep-panel-title"><i class="bi bi-person me-2"></i><?= $v->te('account.profile') ?></div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="display_name"><?= $v->te('users.display_name') ?></label>
                    <input class="form-control" id="display_name" name="display_name" value="<?= $v->e($u['display_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email"><?= $v->te('users.email') ?></label>
                    <input class="form-control" type="email" id="email" name="email" value="<?= $v->e($u['email']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="locale"><?= $v->te('common.language') ?></label>
                    <select class="form-select" id="locale" name="locale">
                        <option value=""><?= $v->te('account.locale_auto') ?></option>
                        <?php foreach ($languages as $code => $name): ?>
                            <option value="<?= $v->e($code) ?>"<?= ($u['locale'] ?? '') === $code ? ' selected' : '' ?>><?= $v->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="theme"><?= $v->te('account.theme') ?></label>
                    <select class="form-select" id="theme" name="theme">
                        <?php foreach (['auto', 'light', 'dark'] as $theme): ?>
                            <option value="<?= $theme ?>"<?= ($u['theme'] ?? 'auto') === $theme ? ' selected' : '' ?>><?= $v->te('theme.' . $theme) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button></div>
            </div>
        </form>

        <form method="post" action="<?= $v->url('/account/password') ?>" class="aep-panel">
            <?= $v->csrfField() ?>
            <div class="aep-panel-title"><i class="bi bi-key me-2"></i><?= $v->te('account.change_password') ?></div>
            <input type="text" name="username" value="<?= $v->e($u['username']) ?>" autocomplete="username" class="d-none" aria-hidden="true" tabindex="-1">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="current_password"><?= $v->te('account.current_password') ?></label>
                    <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="new_password"><?= $v->te('users.new_password') ?></label>
                    <input class="form-control" type="password" id="new_password" name="new_password" minlength="10" required autocomplete="new-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="new_password_confirm"><?= $v->te('account.password_confirm') ?></label>
                    <input class="form-control" type="password" id="new_password_confirm" name="new_password_confirm" minlength="10" required autocomplete="new-password">
                </div>
                <div class="col-12 text-end"><button class="btn btn-primary"><?= $v->te('account.change_password') ?></button></div>
            </div>
        </form>
    </div>

    <div class="col-xl-6" id="security">
        <div class="aep-panel mb-3">
            <div class="aep-panel-title"><i class="bi bi-phone me-2"></i><?= $v->te('account.totp') ?>
                <?php if ($u['totp_enabled']): ?><span class="badge text-bg-success ms-2"><?= $v->te('common.active') ?></span><?php endif; ?>
            </div>
            <?php if ($u['totp_enabled']): ?>
                <p class="small text-body-secondary"><?= $v->te('account.totp_active_hint') ?></p>
                <form method="post" action="<?= $v->url('/account/totp/disable') ?>" data-confirm="<?= $v->te('account.totp_disable_confirm') ?>">
                    <?= $v->csrfField() ?>
                    <button class="btn btn-outline-danger btn-sm"><?= $v->te('account.totp_disable') ?></button>
                </form>
            <?php elseif ($totpSecret !== ''): ?>
                <ol class="small ps-3">
                    <li><?= $v->te('account.totp_step1') ?></li>
                    <li><?= $v->te('account.totp_step2') ?></li>
                </ol>
                <div class="d-flex flex-wrap gap-3 align-items-start">
                    <div class="aep-qr" data-qr="<?= $v->e($totpUri) ?>" role="img" aria-label="QR code"></div>
                    <div class="flex-grow-1">
                        <div class="small text-body-secondary"><?= $v->te('account.totp_manual') ?></div>
                        <code class="d-block user-select-all mb-3 text-break"><?= $v->e(trim(chunk_split($totpSecret, 4, ' '))) ?></code>
                        <form method="post" action="<?= $v->url('/account/totp/enable') ?>" class="d-flex gap-2">
                            <?= $v->csrfField() ?>
                            <input class="form-control aep-code-input" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code" placeholder="000000" aria-label="<?= $v->te('auth.code') ?>">
                            <button class="btn btn-primary"><?= $v->te('auth.verify') ?></button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <p class="small text-body-secondary"><?= $v->te('account.totp_hint') ?></p>
                <form method="post" action="<?= $v->url('/account/totp/start') ?>">
                    <?= $v->csrfField() ?>
                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-qr-code me-1"></i><?= $v->te('account.totp_setup') ?></button>
                </form>
            <?php endif; ?>
        </div>

        <div class="aep-panel mb-3">
            <div class="aep-panel-title"><i class="bi bi-envelope-check me-2"></i><?= $v->te('account.email_2fa') ?>
                <?php if ($u['email_2fa_enabled']): ?><span class="badge text-bg-success ms-2"><?= $v->te('common.active') ?></span><?php endif; ?>
            </div>
            <p class="small text-body-secondary"><?= $v->te('account.email_2fa_hint') ?></p>
            <form method="post" action="<?= $v->url('/account/email-2fa') ?>">
                <?= $v->csrfField() ?>
                <input type="hidden" name="enabled" value="<?= $u['email_2fa_enabled'] ? '0' : '1' ?>">
                <button class="btn btn-sm <?= $u['email_2fa_enabled'] ? 'btn-outline-danger' : 'btn-outline-primary' ?>"><?= $v->te($u['email_2fa_enabled'] ? 'account.email_2fa_disable' : 'account.email_2fa_enable') ?></button>
            </form>
        </div>

        <div class="aep-panel">
            <div class="aep-panel-title"><i class="bi bi-fingerprint me-2"></i><?= $v->te('account.passkeys') ?></div>
            <p class="small text-body-secondary"><?= $v->te('account.passkeys_hint') ?></p>
            <?php if ($passkeys !== []): ?>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($passkeys as $pk): ?>
                        <li class="list-group-item d-flex align-items-center gap-2 px-0">
                            <i class="bi bi-key-fill text-body-secondary"></i>
                            <div class="flex-grow-1">
                                <div class="fw-semibold small"><?= $v->e($pk['name']) ?></div>
                                <div class="small text-body-secondary"><?= $v->te('account.passkey_created') ?> <?= $v->date($pk['created_at'], false) ?> · <?= $v->te('account.passkey_used') ?> <?= $v->date($pk['last_used_at'] ?? null) ?></div>
                            </div>
                            <form method="post" action="<?= $v->url('/account/passkeys/' . $pk['id'] . '/delete') ?>" data-confirm="<?= $v->te('common.confirm_delete') ?>">
                                <?= $v->csrfField() ?>
                                <button class="btn btn-sm btn-link text-danger" aria-label="<?= $v->te('common.delete') ?>"><i class="bi bi-trash"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="d-flex gap-2">
                <input class="form-control form-control-sm" data-passkey-name placeholder="<?= $v->te('account.passkey_name') ?>" value="<?= $v->e($v->t('account.passkey_default_name')) ?>" maxlength="100" aria-label="<?= $v->te('account.passkey_name') ?>">
                <button class="btn btn-sm btn-outline-primary text-nowrap" type="button" data-passkey-register data-options-url="<?= $v->url('/account/passkeys/options') ?>" data-verify-url="<?= $v->url('/account/passkeys') ?>">
                    <i class="bi bi-plus-lg me-1"></i><?= $v->te('account.passkey_add') ?>
                </button>
            </div>
            <div class="small text-danger mt-2 d-none" data-passkey-error></div>
        </div>
    </div>
</div>
