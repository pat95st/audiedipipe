<?php /** @var \AudiEdiPipe\Http\View $v */ ?>
<div class="text-center py-3">
    <i class="bi bi-wifi-off display-4 text-body-secondary"></i>
    <h1 class="h5 mt-3"><?= $v->te('errors.offline') ?></h1>
    <p class="text-body-secondary"><?= $v->te('errors.offline_hint') ?></p>
    <a href="<?= $v->url('/') ?>" class="btn btn-primary"><?= $v->te('common.retry') ?></a>
</div>
