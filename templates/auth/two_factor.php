<?php /** @var \AudiEdiPipe\Http\View $v */ ?>
<h1 class="h5 mb-1"><?= $v->te('auth.two_factor') ?></h1>
<p class="text-body-secondary small mb-3">
    <?= $method === 'email' ? $v->te('auth.two_factor_email_hint') : $v->te('auth.two_factor_totp_hint') ?>
</p>
<form method="post" action="<?= $v->url('/login/2fa') ?>" class="vstack gap-3">
    <?= $v->csrfField() ?>
    <input type="hidden" name="method" value="<?= $v->e($method) ?>">
    <input class="form-control form-control-lg text-center aep-code-input" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus placeholder="000000">
    <button class="btn btn-primary w-100" type="submit"><?= $v->te('auth.verify') ?></button>
</form>
<div class="d-flex flex-wrap gap-2 justify-content-between mt-3 small">
    <?php if ($hasTotp && $method !== 'totp'): ?>
        <a href="?m=totp" class="link-secondary" data-switch-2fa="totp"><?= $v->te('auth.use_totp') ?></a>
    <?php endif; ?>
    <?php if ($hasEmail): ?>
        <form method="post" action="<?= $v->url('/login/2fa/email') ?>">
            <?= $v->csrfField() ?>
            <button class="btn btn-link btn-sm p-0 link-secondary" type="submit">
                <?= $method === 'email' ? $v->te('auth.resend_code') : $v->te('auth.use_email') ?>
            </button>
        </form>
    <?php endif; ?>
    <a class="link-secondary" href="<?= $v->url('/login') ?>"><?= $v->te('common.cancel') ?></a>
</div>
