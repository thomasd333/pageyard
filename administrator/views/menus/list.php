<?php
/**
 * Menus: Manage.
 * @var list<array> $items
 */

use Pageyard\Admin\Form;
use Pageyard\Admin\Grid;
use Pageyard\Uri;

defined('PAGEYARD') or exit;
?>
<p class="text-body-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e(t('MENUS_INTRO')) ?></p>

<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>">
    <?= Form::token() ?>
    <?php if (!$items): ?>
        <?= Grid::empty(false) ?>
    <?php else: ?>
        <div class="card py-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 py-list">
                    <thead>
                    <tr>
                        <th class="py-col-check"><?= Grid::checkAll() ?></th>
                        <th scope="col"><?= e(t('FIELD_TITLE')) ?></th>
                        <th scope="col" class="text-center"><?= e(t('MENUS_ITEMS')) ?></th>
                        <th scope="col" class="text-center d-none d-md-table-cell"><?= e(t('MENUS_BLOCKS')) ?></th>
                        <th scope="col" class="text-end d-none d-md-table-cell">ID</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $i => $m): ?>
                        <tr>
                            <td class="py-col-check"><?= Grid::check($i, (int) $m['id'], $m['title']) ?></td>
                            <th scope="row" class="fw-normal"><a href="<?= e(Uri::admin('view=menu&id=' . $m['id'])) ?>"><?= e($m['title']) ?></a></th>
                            <td class="text-center">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(Uri::admin('view=menuitems&menu=' . $m['id'])) ?>">
                                    <i class="bi bi-list-nested me-1" aria-hidden="true"></i><?= e(t('MENUS_MANAGE_ITEMS')) ?>
                                    <span class="badge text-bg-primary ms-1"><?= (int) $m['items'] ?></span>
                                </a>
                            </td>
                            <td class="text-center d-none d-md-table-cell">
                                <?php if ((int) $m['blocks']): ?>
                                    <span class="badge text-bg-light border"><?= (int) $m['blocks'] ?></span>
                                <?php else: ?>
                                    <span class="small text-warning-emphasis" title="<?= e(t('MENUS_NOT_SHOWN_HINT')) ?>"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i><?= e(t('MENUS_NOT_SHOWN')) ?></span>
                                <?php endif ?>
                            </td>
                            <td class="text-end d-none d-md-table-cell text-body-secondary"><?= (int) $m['id'] ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif ?>
</form>
