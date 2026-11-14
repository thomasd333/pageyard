<?php
/**
 * Pages: Manage.
 * @var array $state
 * @var list<array> $items
 * @var Pageyard\Pagination $pagination
 * @var int $limit
 * @var array<int, string> $categories
 * @var list<string> $languages
 * @var array{type: string, id: int}|null $home
 */

use Pageyard\Admin\Form;
use Pageyard\Admin\Grid;
use Pageyard\Images;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$filtered = $state['search'] !== '' || $state['state'] !== '' || $state['access'] !== '' || $state['category'] !== '';
$categoryOptions = ['none' => t('PAGES_NO_CATEGORY')] + array_map('strval', $categories);
// The manual order belongs to one category, and moving only makes sense with nothing hidden.
$inCategory = ctype_digit($state['category']);
$reordering = $inCategory && $state['sort'] === 'ordering' && $state['dir'] === 'asc' && $state['search'] === '' && $state['state'] === '';
?>
<form class="py-filters" method="get" action="<?= e(Uri::admin()) ?>">
    <input type="hidden" name="view" value="pages">
    <?= Grid::search($state['search'], t('LIST_SEARCH_HINT')) ?>
    <?= Grid::filter('state', t('LIST_STATE'), ['1' => t('STATE_PUBLISHED'), '0' => t('STATE_UNPUBLISHED')], $state['state']) ?>
    <?= Grid::filter('category', t('PAGES_CATEGORY'), $categoryOptions, $state['category']) ?>
    <?= Pageyard\Accounts::enabled() || $state['access'] !== '' ? Grid::filter('access', t('ACCESS'), Pageyard\Admin\Form::accessOptions(false), $state['access']) : '' ?>
    <?= $filtered ? Grid::clear('pages') : '' ?>
    <?= Grid::limit($limit) ?>
</form>

<?php if ($home === null): ?>
    <div class="alert alert-warning"><i class="bi bi-house-exclamation me-2" aria-hidden="true"></i><?= e(t('HOME_NONE')) ?></div>
<?php endif ?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>">
    <?= Form::token() ?>
    <?php if (!$items): ?>
        <?= Grid::empty($filtered) ?>
    <?php else: ?>
        <div class="card py-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 py-list">
                    <thead>
                    <tr>
                        <th class="py-col-check"><?= Grid::checkAll() ?></th>
                        <th scope="col" class="text-center"><?= Grid::sort('pages', $state, 'state', t('LIST_STATE')) ?></th>
                        <th scope="col" class="text-center"><?= e(t('HOME_COLUMN')) ?></th>
                        <?php if ($inCategory): ?><th scope="col" class="text-nowrap" title="<?= e(t('PAGES_ORDER_HINT')) ?>"><?= Grid::sort('pages', $state, 'ordering', t('PAGES_ORDER')) ?></th><?php endif ?>
                        <th scope="col"><?= Grid::sort('pages', $state, 'title', t('FIELD_TITLE')) ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?= Grid::sort('pages', $state, 'category', t('PAGES_CATEGORY')) ?></th>
                        <?php if (count($languages) > 1): ?><th scope="col" class="d-none d-md-table-cell"><?= e(t('LIST_LANGUAGES')) ?></th><?php endif ?>
                        <th scope="col" class="d-none d-lg-table-cell"><?= Grid::sort('pages', $state, 'publish_up', t('PAGES_DATE')) ?></th>
                        <th scope="col" class="d-none d-xl-table-cell"><?= e(t('PAGES_AUTHOR')) ?></th>
                        <th scope="col" class="text-end d-none d-md-table-cell"><?= Grid::sort('pages', $state, 'id', 'ID') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $i => $p): ?>
                        <?php $title = $p['title'] ?? t('LIST_NO_TITLE') ?>
                        <tr>
                            <td class="py-col-check"><?= Grid::check($i, (int) $p['id'], $title) ?></td>
                            <td class="text-center"><?= Grid::state($i, (bool) $p['state'], 'pages', t('STATE_PUBLISHED'), t('STATE_UNPUBLISHED')) ?></td>
                            <td class="text-center"><?= Grid::home($i, 'pages', $home !== null && $home['type'] === 'page' && $home['id'] === (int) $p['id']) ?></td>
                            <?php if ($inCategory): ?>
                                <td><?= $reordering ? Grid::order($i, 'pages', $pagination->offset() + $i === 0, $pagination->offset() + $i === $pagination->total - 1) : (int) $p['ordering'] ?></td>
                            <?php endif ?>
                            <th scope="row" class="fw-normal">
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($p['image'] !== ''): ?>
                                        <img class="py-thumb-xs" src="<?= e(Uri::root() . Images::thumb($p['image'], 160)) ?>" alt="" loading="lazy">
                                    <?php endif ?>
                                    <div>
                                        <a href="<?= e(Uri::admin('view=page&id=' . $p['id'])) ?>"><?= e($title) ?></a><?= Grid::access((int) $p['access']) ?>
                                        <div class="small text-body-secondary"><?= e(t('FIELD_ALIAS')) ?>: <?= e($p['alias'] ?? '') ?></div>
                                    </div>
                                </div>
                            </th>
                            <td class="d-none d-md-table-cell">
                                <?php if ($p['category_id'] !== null): ?>
                                    <a class="link-secondary" href="<?= e(Uri::admin('view=pages&category=' . $p['category_id'])) ?>"><?= e($p['category'] ?? '#' . $p['category_id']) ?></a>
                                <?php else: ?>
                                    <span class="text-body-tertiary">&ndash;</span>
                                <?php endif ?>
                            </td>
                            <?php if (count($languages) > 1): ?><td class="d-none d-md-table-cell text-nowrap"><?= Grid::languages($p['languages'], $languages) ?></td><?php endif ?>
                            <td class="d-none d-lg-table-cell text-nowrap">
                                <?= e(py_date($p['publish_up'])) ?>
                                <?php if ($p['publish_up'] > Pageyard\Database\Db::now()): ?>
                                    <i class="bi bi-clock text-warning ms-1" title="<?= e(t('PAGES_SCHEDULED')) ?>" aria-label="<?= e(t('PAGES_SCHEDULED')) ?>"></i>
                                <?php endif ?>
                            </td>
                            <td class="d-none d-xl-table-cell"><?= e($p['author'] ?? '') ?></td>
                            <td class="text-end d-none d-md-table-cell text-body-secondary"><?= (int) $p['id'] ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif ?>
    <?php if ($items): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            <label class="small text-body-secondary" for="f-move_category"><?= e(t('PAGES_MOVE_LABEL')) ?></label>
            <select class="form-select form-select-sm w-auto" id="f-move_category" name="move_category">
                <option value=""><?= e(t('MENUS_CHOOSE')) ?></option>
                <option value="none"><?= e(t('PAGES_NO_CATEGORY')) ?></option>
                <?php foreach ($categories as $id => $name): ?>
                    <option value="<?= (int) $id ?>"><?= e($name) ?></option>
                <?php endforeach ?>
            </select>
            <button class="btn btn-sm btn-outline-secondary" type="submit" name="task" value="pages.move" data-list-required><?= e(t('PAGES_MOVE')) ?></button>
        </div>
    <?php endif ?>
    <?= Grid::footer($pagination) ?>
</form>
