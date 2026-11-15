<?php
/**
 * PWA basics, included by layout.php AND layout_guest.php (login, 2FA,
 * offline page), so the app is installable before signing in: manifest,
 * icons, theme color, iOS meta tags and pwa.js (service worker registration,
 * load error/offline messages).
 *
 * If a raster logo is uploaded under Settings → Branding it is also used as
 * app icon (/app-icon/…, see AssetController::appIcon()); otherwise the
 * bundled icons from public/assets/img/ apply.
 *
 * @var \AudiEdiPipe\Http\View $v
 */
$settings = $settings ?? [];
$pwaName = (string) ($settings['app_name'] ?? '') ?: 'audiEdiPipe';
$pwaIcons = new \AudiEdiPipe\Infrastructure\Branding\AppIcons(APP_ROOT . '/public', (string) ($settings['logo'] ?? ''));
$pwaTheme = (string) ($settings['primary_color'] ?? '');
$pwaTheme = preg_match('/^#[0-9a-f]{6}$/i', $pwaTheme) === 1 ? $pwaTheme : '#e8590c';
?>
    <link rel="manifest" href="<?= $v->url('/manifest.webmanifest') ?>">
    <meta name="theme-color" content="<?= $v->e($pwaTheme) ?>">
    <meta name="application-name" content="<?= $v->e($pwaName) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= $v->e($pwaName) ?>">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<?php if ($pwaIcons->hasSvgLogo()): ?>
    <link rel="icon" href="<?= $v->e($v->url($pwaIcons->logo())) ?>" type="image/svg+xml">
<?php else: ?>
    <link rel="icon" href="<?= $v->asset('img/logo.svg') ?>" type="image/svg+xml">
<?php endif; ?>
<?php if ($pwaIcons->hasRasterLogo()): ?>
    <link rel="icon" href="<?= $v->url('/app-icon/192') ?>?v=<?= $v->e($pwaIcons->version()) ?>" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="<?= $v->url('/app-icon/apple') ?>?v=<?= $v->e($pwaIcons->version()) ?>">
<?php else: ?>
    <link rel="icon" href="<?= $v->asset('img/icon-192.png') ?>" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="<?= $v->asset('img/apple-touch-icon.png') ?>">
<?php endif; ?>
    <script src="<?= $v->asset('js/pwa.js') ?>"></script>
