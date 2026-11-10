<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var string $content */
$settings = $settings ?? [];
$appName = (string) ($settings['app_name'] ?? 'audiEdiPipe');
$logo = (string) ($settings['logo'] ?? '');
$path = (string) ($path ?? '/');
$nav = [
    ['/', 'bi-speedometer', 'nav.dashboard', null],
    ['/jobs', 'bi-diagram-3', 'nav.jobs', 'jobs.view'],
    ['/upload', 'bi-cloud-arrow-up', 'nav.upload', 'jobs.upload'],
    ['/pipelines', 'bi-bezier2', 'nav.pipelines', 'pipelines.manage'],
    ['/sources', 'bi-hdd-network', 'nav.sources', 'sources.manage'],
    ['/templates', 'bi-tags', 'nav.templates', 'templates.manage'],
    ['/publish', 'bi-broadcast-pin', 'nav.publish', 'publish.manage'],
];
$admin = [
    ['/users', 'bi-people', 'nav.users', 'users.manage'],
    ['/settings', 'bi-gear', 'nav.settings', 'settings.manage'],
];
$isActive = static fn (string $href): bool => $href === '/' ? $path === '/' : str_starts_with($path, $href);
$jsI18n = array_filter(
    $v->translator()->catalogue('en'),
    static fn (string $k): bool => str_starts_with($k, 'js.') || str_starts_with($k, 'fx.') || str_starts_with($k, 'stage.'),
    ARRAY_FILTER_USE_KEY,
);
$jsI18n = array_merge($jsI18n, array_filter(
    $v->translator()->catalogue($v->locale()),
    static fn (string $k): bool => str_starts_with($k, 'js.') || str_starts_with($k, 'fx.') || str_starts_with($k, 'stage.'),
    ARRAY_FILTER_USE_KEY,
));
?>
<!doctype html>
<html lang="<?= $v->e($v->locale()) ?>" data-theme-pref="<?= $v->e($theme ?? 'auto') ?>" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $v->e(($title ?? '') !== '' ? $title . ' · ' . $appName : $appName) ?></title>
    <meta name="theme-color" content="<?= $v->e($settings['primary_color'] ?? '#e8590c') ?>">
    <meta name="csrf-token" content="<?= $v->e($csrf ?? '') ?>">
    <meta name="base-url" content="<?= $v->e(rtrim($v->url('/'), '/')) ?>">
    <link rel="manifest" href="<?= $v->url('/manifest.webmanifest') ?>">
    <link rel="icon" href="<?= $v->asset('img/logo.svg') ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= $v->asset('img/apple-touch-icon.png') ?>">
    <script src="<?= $v->asset('js/theme.js') ?>"></script>
    <link rel="stylesheet" href="<?= $v->asset('vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= $v->asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= $v->asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= $v->url('/theme.css') ?>?v=<?= $v->e(substr(md5(($settings['primary_color'] ?? '') . ($settings['secondary_color'] ?? '')), 0, 8)) ?>">
</head>
<body class="aep-app">
<script type="application/json" id="aep-i18n"><?= $v->json($jsI18n) ?></script>
<a class="visually-hidden-focusable" href="#main"><?= $v->te('common.skip_to_content') ?></a>

<nav class="navbar aep-topbar sticky-top px-2 px-lg-3">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-link text-body d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#aepSidebar" aria-controls="aepSidebar" aria-label="<?= $v->te('common.menu') ?>">
            <i class="bi bi-list fs-4"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center gap-2 me-0" href="<?= $v->url('/') ?>">
            <?php if ($logo !== ''): ?>
                <img src="<?= $v->e($v->url($logo)) ?>" alt="" class="aep-logo">
            <?php else: ?>
                <img src="<?= $v->asset('img/logo.svg') ?>" alt="" class="aep-logo">
            <?php endif; ?>
            <span class="fw-semibold aep-brand-text"><?= $v->e($appName) ?></span>
        </a>
    </div>
    <div class="d-flex align-items-center gap-1">
        <span class="aep-online-indicator d-none" id="aepOffline" title="Offline"><i class="bi bi-wifi-off"></i></span>
        <div class="dropdown">
            <button class="btn btn-sm btn-link text-body" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= $v->te('common.language') ?>">
                <i class="bi bi-translate"></i> <span class="text-uppercase small"><?= $v->e($v->locale()) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach ($v->translator()->available() as $code => $name): ?>
                    <li><a class="dropdown-item<?= $code === $v->locale() ? ' active' : '' ?>" href="<?= $v->url('/locale/' . $code) ?>"><?= $v->e($name) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button class="btn btn-sm btn-link text-body" type="button" data-theme-toggle aria-label="<?= $v->te('common.toggle_theme') ?>" title="<?= $v->te('common.toggle_theme') ?>">
            <i class="bi bi-circle-half"></i>
        </button>
        <?php if (isset($user) && is_array($user)): ?>
        <div class="dropdown">
            <button class="btn btn-sm btn-link text-body d-flex align-items-center gap-1" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="aep-avatar"><?= $v->e(mb_strtoupper(mb_substr((string) ($user['display_name'] ?: $user['username']), 0, 1))) ?></span>
                <span class="d-none d-md-inline"><?= $v->e($user['display_name'] ?: $user['username']) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text small text-body-secondary"><?= $v->te('role.' . $user['role']) ?></span></li>
                <li><a class="dropdown-item" href="<?= $v->url('/account') ?>"><i class="bi bi-person-gear me-2"></i><?= $v->te('nav.account') ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="<?= $v->url('/logout') ?>">
                        <?= $v->csrfField() ?>
                        <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i><?= $v->te('auth.logout') ?></button>
                    </form>
                </li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</nav>

<div class="aep-shell">
    <aside class="offcanvas-lg offcanvas-start aep-sidebar" tabindex="-1" id="aepSidebar" aria-label="<?= $v->te('common.menu') ?>">
        <div class="offcanvas-header d-lg-none">
            <span class="fw-semibold"><?= $v->e($appName) ?></span>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#aepSidebar" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body flex-column p-2">
            <ul class="nav nav-pills flex-column gap-1 w-100">
                <?php foreach ($nav as [$href, $icon, $label, $perm]): ?>
                    <?php if ($perm === null || $v->can($perm)): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $isActive($href) ? ' active' : '' ?>" href="<?= $v->url($href) ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>>
                                <i class="bi <?= $icon ?> me-2"></i><?= $v->te($label) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <?php if ($v->can('users.manage') || $v->can('settings.manage')): ?>
                <div class="aep-nav-heading"><?= $v->te('nav.administration') ?></div>
                <ul class="nav nav-pills flex-column gap-1 w-100">
                    <?php foreach ($admin as [$href, $icon, $label, $perm]): ?>
                        <?php if ($v->can($perm)): ?>
                            <li class="nav-item">
                                <a class="nav-link<?= $isActive($href) ? ' active' : '' ?>" href="<?= $v->url($href) ?>">
                                    <i class="bi <?= $icon ?> me-2"></i><?= $v->te($label) ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="mt-auto pt-3 small text-body-secondary aep-sidebar-footer">
                <span class="aep-led aep-led-green"></span> audiEdiPipe v<?= $v->e(\AudiEdiPipe\Kernel\App::VERSION) ?>
            </div>
        </div>
    </aside>

    <main id="main" class="aep-main">
        <?php foreach (($flashes ?? []) as $flash): ?>
            <div class="alert alert-<?= $v->e($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= $v->e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="aepToasts"></div>

<script src="<?= $v->asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= $v->asset('js/app.js') ?>"></script>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= $v->asset($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
