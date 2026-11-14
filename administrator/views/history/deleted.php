<?php
/**
 * Content > Deleted: pages and blocks that can still be brought back.
 * @var list<array> $items newest first
 * @var int $days 0 = history off
 */

use Pageyard\Admin\Form;
use Pageyard\Revisions;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$types = ['page' => t('HISTORY_TYPE_PAGE'), 'block' => t('HISTORY_TYPE_BLOCK')];
?>
<?php if ($days === 0): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i><?= e(t('HISTORY_OFF')) ?></div>
<?php else: ?>
    <p class="text-body-secondary small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e(t('DELETED_INTRO', $days)) ?></p>
<?php endif ?>

<div class="card py-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 py-list">
            <thead>
            <tr>
                <th><?= e(t('FIELD_TITLE')) ?></th>
                <th><?= e(t('HISTORY_TYPE')) ?></th>
                <th><?= e(t('DELETED_WHEN')) ?></th>
                <th><?= e(t('HISTORY_EXPIRES')) ?></th>
                <th class="text-end"><span class="visually-hidden"><?= e(t('TOOLBAR_ACTIONS')) ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $r): ?>
                <tr>
                    <td><?= e($r['title']) ?></td>
                    <td><?= e($types[$r['item_type']] ?? $r['item_type']) ?></td>
                    <td class="small text-body-secondary"><?= e(py_date($r['created'])) ?></td>
                    <td class="small text-body-secondary"><?= e(py_date(Revisions::expires($r['created']), 'Y-m-d')) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(Uri::admin('view=history&type=' . $r['item_type'] . '&id=' . (int) $r['item_id'])) ?>"><i class="bi bi-clock-history me-1" aria-hidden="true"></i><?= e(t('HISTORY_TITLE')) ?></a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(Uri::admin('view=revision&id=' . (int) $r['id'])) ?>"><i class="bi bi-eye me-1" aria-hidden="true"></i><?= e(t('TOOLBAR_PREVIEW')) ?></a>
                        <form class="d-inline" method="post" action="<?= e(Uri::admin()) ?>" data-confirm="<?= e(t('HISTORY_CONFIRM_DELETED')) ?>">
                            <?= Form::token() ?>
                            <input type="hidden" name="rid" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary" type="submit" name="task" value="history.restore"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i><?= e(t('HISTORY_RESTORE')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            <?php if (!$items): ?>
                <tr><td colspan="5" class="text-center text-body-secondary py-4"><?= e(t('DELETED_EMPTY')) ?></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
</div>
