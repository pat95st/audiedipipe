<?php /** @var \AudiEdiPipe\Http\View $v */ ?>
<h1 class="h5 mb-3"><?= $v->te('auth.login') ?></h1>
<form method="post" action="<?= $v->url('/login') ?>" class="vstack gap-3" autocomplete="on">
    <?= $v->csrfField() ?>
    <div>
        <label class="form-label" for="username"><?= $v->te('auth.username_or_email') ?></label>
        <input class="form-control" id="username" name="username" required autofocus autocomplete="username webauthn">
    </div>
    <div>
        <label class="form-label" for="password"><?= $v->te('auth.password') ?></label>
        <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i><?= $v->te('auth.login') ?></button>
</form>
<div class="aep-divider"><span><?= $v->te('common.or') ?></span></div>
<button class="btn btn-outline-secondary w-100" type="button" data-passkey-login data-options-url="<?= $v->url('/login/passkey/options') ?>" data-verify-url="<?= $v->url('/login/passkey') ?>">
    <i class="bi bi-fingerprint me-1"></i><?= $v->te('auth.login_passkey') ?>
</button>
<div class="small text-danger mt-2 d-none" data-passkey-error></div>
