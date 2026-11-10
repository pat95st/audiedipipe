<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var string $content */
$settings = $settings ?? [];
$appName = (string) ($settings['app_name'] ?? 'audiEdiPipe');
$logo = (string) ($settings['logo'] ?? '');
$jsI18n = array_filter(
    array_merge($v->translator()->catalogue('en'), $v->translator()->catalogue($v->locale())),
    static fn (string $k): bool => str_starts_with($k, 'js.'),
    ARRAY_FILTER_USE_KEY,
);
?>
<!doctype html>
<html lang="<?= $v->e($v->locale()) ?>" data-theme-pref="auto" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $v->e(($title ?? '') !== '' ? $title . ' · ' . $appName : $appName) ?></title>
    <meta name="theme-color" content="<?= $v->e($settings['primary_color'] ?? '#e8590c') ?>">
    <meta name="csrf-token" content="<?= $v->e($csrf ?? '') ?>">
    <meta name="base-url" content="<?= $v->e(rtrim($v->url('/'), '/')) ?>">
    <link rel="manifest" href="<?= $v->url('/manifest.webmanifest') ?>">
    <link rel="icon" href="<?= $v->asset('img/logo.svg') ?>" type="image/svg+xml">
    <script src="<?= $v->asset('js/theme.js') ?>"></script>
    <link rel="stylesheet" href="<?= $v->asset('vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= $v->asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= $v->asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= $v->url('/theme.css') ?>">
</head>
<body class="aep-guest">
<script type="application/json" id="aep-i18n"><?= $v->json($jsI18n) ?></script>
<div class="aep-guest-wrap">
    <div class="aep-guest-brand">
        <img src="<?= $v->e($logo !== '' ? $v->url($logo) : $v->asset('img/logo.svg')) ?>" alt="" class="aep-logo-lg">
        <div>
            <div class="h4 mb-0 fw-semibold"><?= $v->e($appName) ?></div>
            <div class="small text-body-secondary"><?= $v->te('app.tagline') ?></div>
        </div>
    </div>
    <div class="aep-guest-card <?= $v->e($cardClass ?? '') ?>">
        <?php foreach (($flashes ?? []) as $flash): ?>
            <div class="alert alert-<?= $v->e($flash['type']) ?>" role="alert"><?= $v->e($flash['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </div>
    <div class="d-flex justify-content-center gap-3 mt-3 small">
        <?php foreach ($v->translator()->available() as $code => $name): ?>
            <a href="<?= $v->url('/locale/' . $code) ?>" class="link-secondary<?= $code === $v->locale() ? ' fw-semibold' : '' ?>"><?= $v->e($name) ?></a>
        <?php endforeach; ?>
        <button class="btn btn-link btn-sm p-0 link-secondary" type="button" data-theme-toggle><i class="bi bi-circle-half"></i></button>
    </div>
</div>
<script src="<?= $v->asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= $v->asset('js/app.js') ?>"></script>
</body>
</html>
