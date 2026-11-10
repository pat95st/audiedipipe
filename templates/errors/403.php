<?php /** @var \AudiEdiPipe\Http\View $v */ ?>
<div class="aep-empty py-5">
    <div class="display-1 fw-bold aep-lcd-text">403</div>
    <p class="lead"><?= $v->te('errors.forbidden') ?></p>
    <a href="<?= $v->url('/') ?>" class="btn btn-primary"><?= $v->te('nav.dashboard') ?></a>
</div>
