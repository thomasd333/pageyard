<?php
/**
 * Control panel.
 * @var int $users
 * @var int $waiting accounts waiting for an administrator's approval
 * @var int $pages
 * @var int $categories
 * @var list<array> $recent
 * @var array<string, string> $system
 * @var array|false|null $backup newest backup, false = none yet, null = not SQLite
 * @var array|null $backupStatus last backup run
 */

use Pageyard\Uri;

defined('PAGEYARD') or exit;

$tiles = [
    [Uri::admin('view=pages'), 'bi-file-earmark-text', t('PAGES_TITLE'), $pages],
    [Uri::admin('view=page'), 'bi-file-earmark-plus', t('PAGES_NEW'), null],
    [Uri::admin('view=categories'), 'bi-folder', t('CATEGORIES_TITLE'), $categories],
    [Uri::admin('view=users'), 'bi-people', t('USERS_TITLE'), $users],
    [Uri::admin('view=config'), 'bi-gear', t('CONFIG_TITLE'), null],
    [Uri::root(), 'bi-box-arrow-up-right', t('VIEW_SITE'), null],
];
?>
<?php if ($backupStatus !== null && !$backupStatus['ok']): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-2" aria-hidden="true"></i><?= e(t('BACKUPS_LAST_FAILED', py_date(gmdate('Y-m-d H:i:s', $backupStatus['time'])), $backupStatus['message'])) ?>
        <a class="alert-link ms-1" href="<?= e(Uri::admin('view=backups')) ?>"><?= e(t('BACKUPS_TITLE')) ?></a></div>
<?php endif ?>
<?php if ($waiting > 0): ?>
    <div class="alert alert-warning"><i class="bi bi-person-exclamation me-2" aria-hidden="true"></i><?= e(t('DASHBOARD_WAITING', $waiting)) ?>
        <a class="alert-link ms-1" href="<?= e(Uri::admin('view=users&status=pending')) ?>"><?= e(t('USERS_TITLE')) ?></a></div>
<?php endif ?>
<div class="row g-4">
    <div class="col-12">
        <div class="card py-card">
            <div class="card-header"><?= e(t('DASHBOARD_QUICK')) ?></div>
            <div class="card-body">
                <div class="py-quickicons">
                    <?php foreach ($tiles as [$href, $icon, $label, $count]): ?>
                        <a class="py-quickicon" href="<?= e($href) ?>"<?= str_starts_with($href, Uri::admin()) ? '' : ' target="_blank" rel="noopener"' ?>>
                            <?php if ($count !== null): ?><span class="py-quickicon-count"><?= (int) $count ?></span><?php endif ?>
                            <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                            <span><?= e($label) ?></span>
                        </a>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card py-card h-100">
            <div class="card-header"><?= e(t('DASHBOARD_LAST_LOGINS')) ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($recent as $r): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="<?= e(Uri::admin('view=user&id=' . $r['id'])) ?>"><?= e($r['name']) ?></a>
                        <small class="text-body-secondary"><?= e(py_date($r['last_login'])) ?></small>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card py-card h-100">
            <div class="card-header"><?= e(t('DASHBOARD_SYSTEM')) ?></div>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($system as $label => $value): ?>
                    <tr><th scope="row" class="ps-3 fw-normal text-body-secondary"><?= e($label) ?></th><td><?= e($value) ?></td></tr>
                <?php endforeach ?>
                <?php if ($backup !== null): ?>
                    <tr><th scope="row" class="ps-3 fw-normal text-body-secondary"><?= e(t('BACKUPS_LAST')) ?></th>
                        <td><a href="<?= e(Uri::admin('view=backups')) ?>"><?= e($backup ? py_date(gmdate('Y-m-d H:i:s', $backup['time'])) : t('BACKUPS_LAST_NONE')) ?></a></td></tr>
                <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
