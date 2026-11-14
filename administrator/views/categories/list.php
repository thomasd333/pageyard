<?php
/**
 * Categories: Manage.
 * @var array $state
 * @var list<array> $items
 * @var Pageyard\Pagination $pagination
 * @var int $limit
 * @var list<string> $languages
 * @var array{type: string, id: int}|null $home
 */

use Pageyard\Admin\Form;
use Pageyard\Admin\Grid;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$filtered = $state['search'] !== '' || $state['state'] !== '' || $state['access'] !== '';
?>
<form class="py-filters" method="get" action="<?= e(Uri::admin()) ?>">
    <input type="hidden" name="view" value="categories">
    <?= Grid::search($state['search'], t('LIST_SEARCH_HINT')) ?>
    <?= Grid::filter('state', t('LIST_STATE'), ['1' => t('STATE_PUBLISHED'), '0' => t('STATE_UNPUBLISHED')], $state['state']) ?>
    <?= Pageyard\Accounts::enabled() || $state['access'] !== '' ? Grid::filter('access', t('ACCESS'), Pageyard\Admin\Form::accessOptions(false), $state['access']) : '' ?>
    <?= $filtered ? Grid::clear('categories') : '' ?>
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
                        <th scope="col" class="text-center"><?= Grid::sort('categories', $state, 'state', t('LIST_STATE')) ?></th>
                        <th scope="col" class="text-center"><?= e(t('HOME_COLUMN')) ?></th>
                        <th scope="col"><?= Grid::sort('categories', $state, 'title', t('FIELD_TITLE')) ?></th>
                        <?php if (count($languages) > 1): ?><th scope="col" class="d-none d-md-table-cell"><?= e(t('LIST_LANGUAGES')) ?></th><?php endif ?>
                        <th scope="col" class="text-center"><?= Grid::sort('categories', $state, 'pages', t('CATEGORIES_PAGES')) ?></th>
                        <th scope="col" class="text-end d-none d-md-table-cell"><?= Grid::sort('categories', $state, 'id', 'ID') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $i => $c): ?>
                        <?php $title = $c['title'] ?? t('LIST_NO_TITLE') ?>
                        <tr>
                            <td class="py-col-check"><?= Grid::check($i, (int) $c['id'], $title) ?></td>
                            <td class="text-center"><?= Grid::state($i, (bool) $c['state'], 'categories', t('STATE_PUBLISHED'), t('STATE_UNPUBLISHED')) ?></td>
                            <td class="text-center"><?= Grid::home($i, 'categories', $home !== null && $home['type'] === 'category' && $home['id'] === (int) $c['id']) ?></td>
                            <th scope="row" class="fw-normal">
                                <a href="<?= e(Uri::admin('view=category&id=' . $c['id'])) ?>"><?= e($title) ?></a><?= Grid::access((int) $c['access']) ?>
                                <div class="small text-body-secondary"><?= e(t('FIELD_ALIAS')) ?>: <?= e($c['alias'] ?? '') ?></div>
                            </th>
                            <?php if (count($languages) > 1): ?><td class="d-none d-md-table-cell text-nowrap"><?= Grid::languages($c['languages'], $languages) ?></td><?php endif ?>
                            <td class="text-center">
                                <a class="badge rounded-pill text-bg-light border text-decoration-none" href="<?= e(Uri::admin('view=pages&category=' . $c['id'])) ?>"
                                   title="<?= e(t('CATEGORIES_SHOW_PAGES')) ?>"><?= (int) $c['pages'] ?></a>
                            </td>
                            <td class="text-end d-none d-md-table-cell text-body-secondary"><?= (int) $c['id'] ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif ?>
    <?= Grid::footer($pagination) ?>
</form>
