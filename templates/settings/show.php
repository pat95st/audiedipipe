<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var array<string, mixed> $s */
/** @var array<string, string> $languages */
/** @var array<string, mixed> $system */
$smtp = is_array($s['smtp'] ?? null) ? $s['smtp'] : [];
?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('nav.settings') ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->te('settings.subtitle') ?></p>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist" data-remember-tab>
    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab"><i class="bi bi-sliders me-1"></i><?= $v->te('settings.general') ?></button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#branding" type="button" role="tab"><i class="bi bi-palette me-1"></i><?= $v->te('settings.branding') ?></button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#mail" type="button" role="tab"><i class="bi bi-envelope me-1"></i><?= $v->te('settings.mail') ?></button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#system" type="button" role="tab"><i class="bi bi-cpu me-1"></i><?= $v->te('settings.system') ?></button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="general" role="tabpanel">
        <form method="post" action="<?= $v->url('/settings') ?>" class="aep-panel" style="max-width: 48rem">
            <?= $v->csrfField() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="app_name"><?= $v->te('settings.app_name') ?></label>
                    <input class="form-control" id="app_name" name="app_name" value="<?= $v->e($s['app_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="default_locale"><?= $v->te('settings.default_locale') ?></label>
                    <select class="form-select" id="default_locale" name="default_locale">
                        <?php foreach ($languages as $code => $name): ?>
                            <option value="<?= $v->e($code) ?>"<?= ($s['default_locale'] ?? 'en') === $code ? ' selected' : '' ?>><?= $v->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="default_theme"><?= $v->te('settings.default_theme') ?></label>
                    <select class="form-select" id="default_theme" name="default_theme">
                        <?php foreach (['auto', 'light', 'dark'] as $theme): ?>
                            <option value="<?= $theme ?>"<?= ($s['default_theme'] ?? 'auto') === $theme ? ' selected' : '' ?>><?= $v->te('theme.' . $theme) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="upload_max_mb"><?= $v->te('settings.upload_max_mb') ?></label>
                    <input class="form-control" type="number" min="1" id="upload_max_mb" name="upload_max_mb" value="<?= (int) ($s['upload_max_mb'] ?? 1024) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="keep_work_files_days"><?= $v->te('settings.keep_work_files_days') ?></label>
                    <input class="form-control" type="number" min="1" id="keep_work_files_days" name="keep_work_files_days" value="<?= (int) ($s['keep_work_files_days'] ?? 14) ?>">
                </div>
                <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button></div>
            </div>
        </form>
    </div>

    <div class="tab-pane fade" id="branding" role="tabpanel">
        <div class="row g-3">
            <div class="col-lg-6">
                <form method="post" action="<?= $v->url('/settings') ?>" class="aep-panel h-100">
                    <?= $v->csrfField() ?>
                    <div class="aep-panel-title"><i class="bi bi-palette me-2"></i><?= $v->te('settings.colors') ?></div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="primary_color"><?= $v->te('settings.primary_color') ?></label>
                            <input class="form-control form-control-color w-100" type="color" id="primary_color" name="primary_color" value="<?= $v->e($s['primary_color'] ?? '#e8590c') ?>" data-color-preview="--bs-primary">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="secondary_color"><?= $v->te('settings.secondary_color') ?></label>
                            <input class="form-control form-control-color w-100" type="color" id="secondary_color" name="secondary_color" value="<?= $v->e($s['secondary_color'] ?? '#1c7ed6') ?>" data-color-preview="--aep-secondary">
                        </div>
                        <div class="col-12">
                            <div class="small text-body-secondary mb-2"><?= $v->te('settings.color_presets') ?></div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ([
                                    ['#e8590c', '#1c7ed6', 'Analog'], ['#d6336c', '#7048e8', 'Neon'], ['#2f9e44', '#f08c00', 'Studio'],
                                    ['#1971c2', '#0ca678', 'Broadcast'], ['#495057', '#fab005', 'Mono'],
                                ] as [$p, $sec, $label]): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" data-color-set="<?= $p ?>,<?= $sec ?>">
                                        <span class="aep-swatch" style="background: <?= $p ?>"></span><span class="aep-swatch" style="background: <?= $sec ?>"></span><?= $label ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2 align-items-center">
                            <button class="btn btn-primary" type="button" tabindex="-1">Primary</button>
                            <span class="badge" style="background: var(--aep-secondary)">Secondary</span>
                            <span class="aep-led aep-led-green on"></span>
                            <button class="btn btn-primary ms-auto" type="submit"><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-lg-6">
                <div class="aep-panel h-100">
                    <div class="aep-panel-title"><i class="bi bi-image me-2"></i><?= $v->te('settings.logo') ?></div>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="aep-logo-preview">
                            <img src="<?= $v->e(($s['logo'] ?? '') !== '' ? $v->url($s['logo']) : $v->asset('img/logo.svg')) ?>" alt="">
                        </div>
                        <div class="small text-body-secondary"><?= $v->te('settings.logo_hint') ?></div>
                    </div>
                    <form method="post" action="<?= $v->url('/settings/logo') ?>" enctype="multipart/form-data" class="d-flex gap-2">
                        <?= $v->csrfField() ?>
                        <input class="form-control" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" required>
                        <button class="btn btn-outline-primary text-nowrap"><i class="bi bi-upload me-1"></i><?= $v->te('common.upload') ?></button>
                    </form>
                    <?php if (($s['logo'] ?? '') !== ''): ?>
                        <form method="post" action="<?= $v->url('/settings') ?>" class="mt-2">
                            <?= $v->csrfField() ?>
                            <input type="hidden" name="remove_logo" value="1">
                            <button class="btn btn-sm btn-link text-danger p-0"><?= $v->te('settings.remove_logo') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="mail" role="tabpanel">
        <div class="row g-3">
            <div class="col-lg-8">
                <form method="post" action="<?= $v->url('/settings') ?>" class="aep-panel" autocomplete="off">
                    <?= $v->csrfField() ?>
                    <div class="aep-panel-title"><i class="bi bi-envelope-at me-2"></i>SMTP</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="smtp_host"><?= $v->te('settings.smtp_host') ?></label>
                            <input class="form-control font-monospace" id="smtp_host" name="smtp[host]" value="<?= $v->e($smtp['host'] ?? '') ?>" placeholder="smtp.example.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="smtp_port"><?= $v->te('settings.smtp_port') ?></label>
                            <input class="form-control" type="number" id="smtp_port" name="smtp[port]" value="<?= (int) ($smtp['port'] ?? 587) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="smtp_enc"><?= $v->te('settings.smtp_encryption') ?></label>
                            <select class="form-select" id="smtp_enc" name="smtp[encryption]">
                                <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => $v->t('common.none')] as $val => $label): ?>
                                    <option value="<?= $val ?>"<?= ($smtp['encryption'] ?? 'tls') === $val ? ' selected' : '' ?>><?= $v->e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="smtp_user"><?= $v->te('settings.smtp_username') ?></label>
                            <input class="form-control" id="smtp_user" name="smtp[username]" value="<?= $v->e($smtp['username'] ?? '') ?>" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="smtp_pass"><?= $v->te('auth.password') ?></label>
                            <input class="form-control" type="password" id="smtp_pass" name="smtp[password]" autocomplete="new-password" placeholder="<?= ($smtp['password_set'] ?? false) ? $v->te('common.unchanged') : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="smtp_from"><?= $v->te('settings.smtp_from_email') ?></label>
                            <input class="form-control" type="email" id="smtp_from" name="smtp[from_email]" value="<?= $v->e($smtp['from_email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="smtp_from_name"><?= $v->te('settings.smtp_from_name') ?></label>
                            <input class="form-control" id="smtp_from_name" name="smtp[from_name]" value="<?= $v->e($smtp['from_name'] ?? '') ?>">
                        </div>
                        <div class="col-12 small text-body-secondary"><i class="bi bi-info-circle me-1"></i><?= $v->te('settings.smtp_hint') ?></div>
                        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-floppy me-1"></i><?= $v->te('common.save') ?></button></div>
                    </div>
                </form>
            </div>
            <div class="col-lg-4">
                <form method="post" action="<?= $v->url('/settings/test-mail') ?>" class="aep-panel">
                    <?= $v->csrfField() ?>
                    <div class="aep-panel-title"><i class="bi bi-send me-2"></i><?= $v->te('settings.test_mail') ?></div>
                    <label class="form-label" for="test_to"><?= $v->te('settings.test_mail_to') ?></label>
                    <input class="form-control mb-2" type="email" id="test_to" name="to" value="<?= $v->e($user['email'] ?? '') ?>">
                    <button class="btn btn-outline-primary w-100"><i class="bi bi-send me-1"></i><?= $v->te('settings.send_test_mail') ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="system" role="tabpanel">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="aep-panel h-100">
                    <div class="aep-panel-title"><i class="bi bi-clock-history me-2"></i><?= $v->te('settings.worker') ?></div>
                    <p class="small text-body-secondary"><?= $v->te('settings.worker_hint') ?></p>
                    <label class="form-label small"><?= $v->te('settings.cron_cli') ?></label>
                    <div class="input-group input-group-sm mb-3">
                        <input class="form-control font-monospace" value="<?= $v->e($system['cron_cli']) ?>" readonly>
                        <button class="btn btn-outline-secondary" type="button" data-copy="<?= $v->e($system['cron_cli']) ?>" aria-label="<?= $v->te('common.copy') ?>"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <label class="form-label small"><?= $v->te('settings.cron_url') ?></label>
                    <div class="input-group input-group-sm mb-3">
                        <input class="form-control font-monospace" value="<?= $v->e($system['cron_url']) ?>" readonly>
                        <button class="btn btn-outline-secondary" type="button" data-copy="<?= $v->e($system['cron_url']) ?>" aria-label="<?= $v->te('common.copy') ?>"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="aep-led <?= $system['last_cron'] !== null && strtotime($system['last_cron'] . ' UTC') > time() - 300 ? 'aep-led-green on' : 'aep-led-amber on' ?>"></span>
                        <span class="small"><?= $v->te('settings.last_cron') ?>: <?= $v->date($system['last_cron']) ?></span>
                        <form method="post" action="<?= $v->url('/settings/worker') ?>" class="ms-auto">
                            <?= $v->csrfField() ?>
                            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-play-fill me-1"></i><?= $v->te('settings.run_worker') ?></button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="aep-panel h-100">
                    <div class="aep-panel-title"><i class="bi bi-info-square me-2"></i><?= $v->te('settings.system_info') ?></div>
                    <dl class="row small mb-0">
                        <dt class="col-5 fw-normal text-body-secondary">audiEdiPipe</dt><dd class="col-7 font-monospace"><?= $v->e($system['version']) ?></dd>
                        <dt class="col-5 fw-normal text-body-secondary">PHP</dt><dd class="col-7 font-monospace"><?= $v->e($system['php']) ?></dd>
                        <dt class="col-5 fw-normal text-body-secondary"><?= $v->te('install.database') ?></dt><dd class="col-7 font-monospace"><?= $v->e($system['db']) ?></dd>
                        <dt class="col-5 fw-normal text-body-secondary">ffmpeg</dt><dd class="col-7 font-monospace text-truncate" title="<?= $v->e($system['ffmpeg'] ?? '') ?>"><?= $v->e($system['ffmpeg'] ?? $v->t('settings.not_found')) ?></dd>
                        <dt class="col-5 fw-normal text-body-secondary">SFTP/SCP</dt><dd class="col-7 font-monospace"><?= $v->e($system['sftp'] ?? $v->t('settings.not_available')) ?></dd>
                        <dt class="col-5 fw-normal text-body-secondary"><?= $v->te('settings.extensions') ?></dt>
                        <dd class="col-7">
                            <?php foreach ($system['extensions'] as $ext => $loaded): ?>
                                <span class="badge <?= $loaded ? 'text-bg-success-subtle text-success-emphasis' : 'text-bg-secondary opacity-50' ?> mb-1"><?= $v->e($ext) ?></span>
                            <?php endforeach; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
