<?php
/** @var \AudiEdiPipe\Http\View $v */
$o = static fn (string $key, string $default = '') => $v->e($old[$key] ?? $default);
?>
<h1 class="h4 mb-1"><i class="bi bi-magic me-2"></i><?= $v->te('install.title') ?></h1>
<p class="text-body-secondary"><?= $v->te('install.intro') ?></p>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><strong><?= $v->te('install.failed') ?></strong><br><?= $v->e($error) ?></div>
<?php endif; ?>

<h2 class="h6 text-uppercase text-body-secondary mt-4"><?= $v->te('install.requirements') ?></h2>
<ul class="list-group mb-2 aep-checklist" id="aep-install-checks" data-check-url="<?= $v->url('/install/check') ?>" aria-live="polite">
    <?= $v->partial('install/_checks', ['checks' => $checks]) ?>
</ul>
<div class="d-flex justify-content-end mb-4">
    <button class="btn btn-outline-secondary btn-sm" type="button" id="aep-install-recheck">
        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"></span>
        <i class="bi bi-arrow-repeat me-1"></i><?= $v->te('install.recheck') ?>
    </button>
</div>

<form method="post" action="<?= $v->url('/install') ?>" class="vstack gap-4">
    <?= $v->csrfField() ?>
    <fieldset>
        <legend class="h6 text-uppercase text-body-secondary"><?= $v->te('install.application') ?></legend>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="app_name"><?= $v->te('settings.app_name') ?></label>
                <input class="form-control" id="app_name" name="app_name" value="<?= $o('app_name', 'audiEdiPipe') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="locale"><?= $v->te('settings.default_locale') ?></label>
                <select class="form-select" id="locale" name="locale">
                    <?php foreach ($languages as $code => $name): ?>
                        <option value="<?= $v->e($code) ?>"<?= ($old['locale'] ?? $v->locale()) === $code ? ' selected' : '' ?>><?= $v->e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="app_url"><?= $v->te('install.app_url') ?></label>
                <input class="form-control" id="app_url" name="app_url" type="url" value="<?= $o('app_url', $defaultUrl) ?>" required>
                <div class="form-text"><?= $v->te('install.app_url_hint') ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="ffmpeg"><?= $v->te('install.ffmpeg_path') ?></label>
                <input class="form-control font-monospace" id="ffmpeg" name="ffmpeg" value="<?= $o('ffmpeg', 'ffmpeg') ?>" data-install-binary autocomplete="off" spellcheck="false">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="ffprobe"><?= $v->te('install.ffprobe_path') ?></label>
                <input class="form-control font-monospace" id="ffprobe" name="ffprobe" value="<?= $o('ffprobe', 'ffprobe') ?>" data-install-binary autocomplete="off" spellcheck="false">
            </div>
            <div class="col-12 form-text mt-1"><?= $v->te('install.binary_path_hint') ?></div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="pseudo_cron" name="pseudo_cron" value="1" checked>
                    <label class="form-check-label" for="pseudo_cron"><?= $v->te('install.pseudo_cron') ?></label>
                </div>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend class="h6 text-uppercase text-body-secondary"><?= $v->te('install.database') ?></legend>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="db_driver"><?= $v->te('install.db_driver') ?></label>
                <select class="form-select" id="db_driver" name="db_driver">
                    <option value="mysql"<?= ($old['db_driver'] ?? '') === 'mysql' ? ' selected' : '' ?><?= in_array('mysql', $drivers, true) ? '' : ' disabled' ?>>MariaDB / MySQL</option>
                    <option value="pgsql"<?= ($old['db_driver'] ?? '') === 'pgsql' ? ' selected' : '' ?><?= in_array('pgsql', $drivers, true) ? '' : ' disabled' ?>>PostgreSQL</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="db_host"><?= $v->te('install.db_host') ?></label>
                <input class="form-control" id="db_host" name="db_host" value="<?= $o('db_host', 'localhost') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="db_port"><?= $v->te('install.db_port') ?></label>
                <input class="form-control" id="db_port" name="db_port" type="number" value="<?= $o('db_port', '3306') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="db_name"><?= $v->te('install.db_name') ?></label>
                <input class="form-control" id="db_name" name="db_name" value="<?= $o('db_name', 'audiedipipe') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="db_user"><?= $v->te('install.db_user') ?></label>
                <input class="form-control" id="db_user" name="db_user" value="<?= $o('db_user', 'audiedipipe') ?>" required autocomplete="off">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="db_password"><?= $v->te('install.db_password') ?></label>
                <input class="form-control" id="db_password" name="db_password" type="password" autocomplete="new-password">
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend class="h6 text-uppercase text-body-secondary"><?= $v->te('install.admin') ?></legend>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="admin_username"><?= $v->te('users.username') ?></label>
                <input class="form-control" id="admin_username" name="admin_username" value="<?= $o('admin_username', 'admin') ?>" required pattern="[a-zA-Z0-9._\-]{3,100}">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="admin_email"><?= $v->te('users.email') ?></label>
                <input class="form-control" id="admin_email" name="admin_email" type="email" value="<?= $o('admin_email') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="admin_password"><?= $v->te('auth.password') ?></label>
                <input class="form-control" id="admin_password" name="admin_password" type="password" minlength="10" required autocomplete="new-password">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="admin_password_confirm"><?= $v->te('account.password_confirm') ?></label>
                <input class="form-control" id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="10" required autocomplete="new-password">
            </div>
        </div>
    </fieldset>

    <button class="btn btn-primary btn-lg" type="submit" id="aep-install-submit"<?= $ok ? '' : ' disabled' ?>>
        <i class="bi bi-rocket-takeoff me-1"></i><?= $v->te('install.submit') ?>
    </button>
    <div class="alert alert-warning mb-0<?= $ok ? ' d-none' : '' ?>" id="aep-install-warning"><?= $v->te('install.fix_requirements') ?></div>
</form>
<script src="<?= $v->asset('js/install.js') ?>"></script>
