<?php
/**
 * Menu items of one menu, in display order (children indented under their parent).
 * @var array<int, string> $menus
 * @var int $menuId
 * @var list<array> $items
 * @var list<string> $languages
 */

use Pageyard\Admin\Form;
use Pageyard\Admin\Grid;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$typeIcons = ['home' => 'bi-house', 'page' => 'bi-file-earmark-text', 'category' => 'bi-folder', 'url' => 'bi-box-arrow-up-right', 'account' => 'bi-person-circle'];
$typeNames = ['home' => t('MENUS_TYPE_HOME'), 'page' => t('MENUS_TYPE_PAGE'), 'category' => t('MENUS_TYPE_CATEGORY'), 'url' => t('MENUS_TYPE_URL'), 'account' => t('MENUS_TYPE_ACCOUNT')];
$editLinks = ['page' => 'view=page&id=', 'category' => 'view=category&id='];
// First/last item among siblings, for the ordering arrows.
$siblings = [];
foreach ($items as $item) {
    $siblings[(int) $item['parent_id']][] = (int) $item['id'];
}
?>
<form class="py-filters" method="get" action="<?= e(Uri::admin()) ?>">
    <input type="hidden" name="view" value="menuitems">
    <label class="col-form-label" for="f-menu"><?= e(t('MENUS_MENU')) ?></label>
    <select class="form-select w-auto" id="f-menu" name="menu" data-autosubmit>
        <?php foreach ($menus as $id => $title): ?>
            <option value="<?= (int) $id ?>"<?= $id === $menuId ? ' selected' : '' ?>><?= e($title) ?></option>
        <?php endforeach ?>
    </select>
    <a class="btn btn-outline-secondary" href="<?= e(Uri::admin('view=menus')) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?= e(t('MENUS_TITLE')) ?></a>
</form>

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
                        <th scope="col" class="text-center"><?= e(t('LIST_STATE')) ?></th>
                        <th scope="col"><?= e(t('MENUS_LABEL')) ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?= e(t('MENUS_TARGET')) ?></th>
                        <?php if (count($languages) > 1): ?><th scope="col" class="d-none d-lg-table-cell"><?= e(t('LIST_LANGUAGES')) ?></th><?php endif ?>
                        <th scope="col" class="text-center"><?= e(t('LIST_ORDER')) ?></th>
                        <th scope="col" class="text-end d-none d-md-table-cell">ID</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $i => $item): ?>
                        <?php
                        $label = $item['label'] ?: ($item['target_title'] ?? t('MENUS_MISSING_TARGET'));
                        $targetId = (int) ($item['type'] === 'page' ? $item['page_id'] : $item['category_id']);
                        $group = $siblings[(int) $item['parent_id']];
                        ?>
                        <tr>
                            <td class="py-col-check"><?= Grid::check($i, (int) $item['id'], $label) ?></td>
                            <td class="text-center"><?= Grid::state($i, (bool) $item['state'], 'menuitems', t('STATE_PUBLISHED'), t('STATE_UNPUBLISHED')) ?></td>
                            <th scope="row" class="fw-normal">
                                <?php if ($item['level'] > 0): ?><span class="text-body-tertiary ms-3 me-1">&#8627;</span><?php endif ?>
                                <a href="<?= e(Uri::admin('view=menuitem&id=' . $item['id'])) ?>"><?= e($label) ?></a>
                                <?php if ($item['label'] && $item['target_title'] && $item['type'] !== 'url'): ?><span class="small text-body-secondary ms-1">(<?= e($item['target_title']) ?>)</span><?php endif ?>
                                <?= Pageyard\Admin\Grid::access((int) ($item['access'] ?? 0)) ?>
                            </th>
                            <td class="d-none d-md-table-cell small">
                                <i class="bi <?= $typeIcons[$item['type']] ?? '' ?> me-1" title="<?= e($typeNames[$item['type']] ?? '') ?>" aria-hidden="true"></i>
                                <?php if ($item['target_state'] === null): ?>
                                    <span class="text-danger"><?= e(t($item['type'] === 'home' ? 'HOME_NONE_SHORT' : 'MENUS_MISSING_TARGET')) ?></span>
                                <?php elseif (isset($editLinks[$item['type']])): ?>
                                    <a class="link-secondary" href="<?= e(Uri::admin($editLinks[$item['type']] . $targetId)) ?>"><?= e($item['target_title'] ?? '#' . $targetId) ?></a>
                                <?php else: ?>
                                    <span class="text-body-secondary"><?= e($item['type'] === 'home' ? $typeNames['home'] . ': ' . ($item['target_title'] ?? '') : $item['target_title']) ?></span>
                                <?php endif ?>
                                <?php if ($item['target_state'] === 0): ?><span class="badge text-bg-warning ms-1"><?= e(t('STATE_UNPUBLISHED')) ?></span><?php endif ?>
                            </td>
                            <?php if (count($languages) > 1): ?><td class="d-none d-lg-table-cell text-nowrap"><?= Grid::languages($item['languages'], $languages) ?></td><?php endif ?>
                            <td class="text-center"><?= Grid::order($i, 'menuitems', $group[0] === (int) $item['id'], end($group) === (int) $item['id']) ?></td>
                            <td class="text-end d-none d-md-table-cell text-body-secondary"><?= (int) $item['id'] ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif ?>
</form>
