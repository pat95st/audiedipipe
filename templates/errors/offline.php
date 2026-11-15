<?php /** @var \AudiEdiPipe\Http\View $v */ ?>
<?php
/*
 * Fallback page of the service worker (public/sw.js) when a page cannot be
 * loaded (no connection / server unreachable). It is shown at the originally
 * requested URL, so "Retry" reloads exactly that page. Behaviour: pwa.js.
 */
?>
<div class="text-center py-3" data-offline-page role="alert">
    <i class="bi bi-wifi-off display-4 text-body-secondary" aria-hidden="true"></i>
    <h1 class="h5 mt-3"><?= $v->te('errors.offline') ?></h1>
    <p class="text-body-secondary" data-offline-reason><?= $v->te('errors.offline_hint') ?></p>
    <div class="d-flex flex-wrap justify-content-center gap-2">
        <a href="" class="btn btn-primary" data-offline-retry><i class="bi bi-arrow-clockwise"></i> <?= $v->te('common.retry') ?></a>
        <a href="<?= $v->url('/') ?>" class="btn btn-outline-secondary"><?= $v->te('errors.offline_home') ?></a>
    </div>
    <div class="small text-body-secondary mt-3" data-offline-status aria-live="polite"></div>
</div>
