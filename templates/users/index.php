<?php
/** @var \AudiEdiPipe\Http\View $v */
/** @var list<array<string, mixed>> $users */
$roleIcons = ['admin' => 'bi-shield-lock', 'cutter' => 'bi-scissors', 'reviewer' => 'bi-clipboard-check'];
?>
<div class="aep-page-header">
    <div>
        <h1 class="h3 mb-0"><?= $v->te('nav.users') ?></h1>
        <p class="text-body-secondary mb-0"><?= $v->te('users.subtitle') ?></p>
    </div>
    <a class="btn btn-primary" href="<?= $v->url('/users/new') ?>"><i class="bi bi-person-plus me-1"></i><?= $v->te('users.new') ?></a>
</div>

<div class="aep-panel p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 aep-table">
            <thead>
            <tr>
                <th><?= $v->te('users.username') ?></th>
                <th class="d-none d-md-table-cell"><?= $v->te('users.email') ?></th>
                <th><?= $v->te('users.role') ?></th>
                <th class="d-none d-lg-table-cell">2FA</th>
                <th class="d-none d-lg-table-cell"><?= $v->te('users.last_login') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= $u['active'] ? '' : 'opacity-50' ?>">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="aep-avatar"><?= $v->e(mb_strtoupper(mb_substr((string) ($u['display_name'] ?: $u['username']), 0, 1))) ?></span>
                            <div>
                                <a class="fw-semibold" href="<?= $v->url('/users/' . $u['id']) ?>"><?= $v->e($u['username']) ?></a>
                                <?php if ($u['display_name']): ?><div class="small text-body-secondary"><?= $v->e($u['display_name']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell small"><?= $v->e($u['email']) ?></td>
                    <td><span class="badge aep-role aep-role-<?= $v->e($u['role']) ?>"><i class="bi <?= $roleIcons[$u['role']] ?? 'bi-person' ?> me-1"></i><?= $v->te('role.' . $u['role']) ?></span></td>
                    <td class="d-none d-lg-table-cell small">
                        <?php if ($u['totp_enabled']): ?><span class="badge text-bg-success-subtle text-success-emphasis">TOTP</span><?php endif; ?>
                        <?php if ($u['email_2fa_enabled']): ?><span class="badge text-bg-info-subtle text-info-emphasis"><?= $v->te('users.email_short') ?></span><?php endif; ?>
                        <?php if (!$u['totp_enabled'] && !$u['email_2fa_enabled']): ?><span class="text-body-secondary">–</span><?php endif; ?>
                    </td>
                    <td class="d-none d-lg-table-cell small"><?= $v->date($u['last_login_at'] ?? null) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= $v->url('/users/' . $u['id']) ?>" aria-label="<?= $v->te('common.edit') ?>"><i class="bi bi-pencil"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mt-2">
    <?php foreach (['admin', 'cutter', 'reviewer'] as $role): ?>
        <div class="col-md-4">
            <div class="aep-panel h-100 small">
                <div class="fw-semibold mb-1"><i class="bi <?= $roleIcons[$role] ?> me-1"></i><?= $v->te('role.' . $role) ?></div>
                <div class="text-body-secondary"><?= $v->te('role.' . $role . '.description') ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
