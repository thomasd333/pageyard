<?php
/**
 * History of one item.
 * @var string $type page | block | css
 * @var list<array> $revisions newest first
 * @var array{date: ?string, author: string}|null $current null = the item no longer exists
 * @var int $days 0 = history off
 * @var int $interval minutes
 */

use Pageyard\Admin\Form;
use Pageyard\Revisions;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$reasons = ['restore' => t('HISTORY_REASON_RESTORE'), 'delete' => t('HISTORY_REASON_DELETE')];
?>
<?php if ($days === 0): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i><?= e(t('HISTORY_OFF')) ?></div>
<?php else: ?>
    <p class="text-body-secondary small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e(t('HISTORY_INTRO', $days)) ?>
        <?= e($interval > 0 ? t('HISTORY_INTRO_THINNING', $interval) : '') ?></p>
<?php endif ?>

<div class="card py-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 py-list">
            <thead>
            <tr>
                <th><?= e(t('HISTORY_VERSION_DATE')) ?></th>
                <?php if ($type === 'page'): ?><th><?= e(t('PAGES_AUTHOR')) ?></th><?php endif ?>
                <?php if ($type !== 'css'): ?><th><?= e(t('FIELD_TITLE')) ?></th><?php endif ?>
                <th><?= e(t('HISTORY_REPLACED')) ?></th>
                <th><?= e(t('HISTORY_EXPIRES')) ?></th>
                <th class="text-end"><span class="visually-hidden"><?= e(t('TOOLBAR_ACTIONS')) ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($current !== null): ?>
                <tr class="table-active">
                    <td><?= e(py_date($current['date'])) ?: '&ndash;' ?> <span class="badge text-bg-success ms-1"><?= e(t('HISTORY_CURRENT')) ?></span></td>
                    <?php if ($type === 'page'): ?><td><?= e($current['author']) ?></td><?php endif ?>
                    <?php if ($type !== 'css'): ?><td></td><?php endif ?>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            <?php endif ?>
            <?php foreach ($revisions as $r): ?>
                <tr>
                    <td>
                        <?= e(py_date($r['version_date'])) ?>
                        <?php if (isset($reasons[$r['reason']])): ?><span class="badge text-bg-secondary ms-1"><?= e($reasons[$r['reason']]) ?></span><?php endif ?>
                    </td>
                    <?php if ($type === 'page'): ?><td><?= e($r['author']) ?></td><?php endif ?>
                    <?php if ($type !== 'css'): ?><td><?= e($r['title']) ?></td><?php endif ?>
                    <td class="small text-body-secondary"><?= e(py_date($r['created'])) ?></td>
                    <td class="small text-body-secondary"><?= e(py_date(Revisions::expires($r['created']), 'Y-m-d')) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(Uri::admin('view=revision&id=' . (int) $r['id'])) ?>"><i class="bi bi-eye me-1" aria-hidden="true"></i><?= e(t('TOOLBAR_PREVIEW')) ?></a>
                        <form class="d-inline" method="post" action="<?= e(Uri::admin()) ?>" data-confirm="<?= e(t($current !== null ? 'HISTORY_CONFIRM' : 'HISTORY_CONFIRM_DELETED')) ?>">
                            <?= Form::token() ?>
                            <input type="hidden" name="rid" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary" type="submit" name="task" value="history.restore"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i><?= e(t('HISTORY_RESTORE')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            <?php if (!$revisions): ?>
                <tr><td colspan="6" class="text-center text-body-secondary py-4"><?= e(t('HISTORY_EMPTY')) ?></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
</div>
