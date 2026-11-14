<?php
/**
 * Blocks grouped by position, in display order.
 * @var array $state
 * @var array<string, list<array>> $groups position => blocks
 * @var list<string> $languages
 */

use Pageyard\Admin\Form;
use Pageyard\Admin\Grid;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$filtered = $state['search'] !== '' || $state['state'] !== '' || $state['access'] !== '' || $state['position'] !== '';
$positions = ['top' => t('BLOCKS_POS_TOP'), 'hero' => t('BLOCKS_POS_HERO'), 'left' => t('BLOCKS_POS_LEFT'), 'right' => t('BLOCKS_POS_RIGHT'), 'footer' => t('BLOCKS_POS_FOOTER')];
$positionHints = ['top' => t('BLOCKS_POS_TOP_HINT'), 'hero' => t('BLOCKS_POS_HERO_HINT'), 'left' => t('BLOCKS_POS_LEFT_HINT'), 'right' => t('BLOCKS_POS_RIGHT_HINT'), 'footer' => t('BLOCKS_POS_FOOTER_HINT')];
$assignments = ['all' => t('BLOCKS_ASSIGN_ALL'), 'only' => t('BLOCKS_ASSIGN_ONLY'), 'except' => t('BLOCKS_ASSIGN_EXCEPT')];
$i = 0;
?>
<form class="py-filters" method="get" action="<?= e(Uri::admin()) ?>">
    <input type="hidden" name="view" value="blocks">
    <?= Grid::search($state['search'], t('BLOCKS_SEARCH_HINT')) ?>
    <?= Grid::filter('state', t('LIST_STATE'), ['1' => t('STATE_PUBLISHED'), '0' => t('STATE_UNPUBLISHED')], $state['state']) ?>
    <?= Grid::filter('position', t('BLOCKS_POSITION'), $positions, $state['position']) ?>
    <?= Pageyard\Accounts::enabled() || $state['access'] !== '' ? Grid::filter('access', t('ACCESS'), Pageyard\Admin\Form::accessOptions(true), $state['access']) : '' ?>
    <?= $filtered ? Grid::clear('blocks') : '' ?>
</form>

<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>">
    <?= Form::token() ?>
    <?php foreach ($groups as $position => $blocks): ?>
        <?php if ($state['position'] !== '' && $state['position'] !== $position) continue; ?>
        <div class="card py-card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><?= e($positions[$position]) ?> <small class="text-body-secondary fw-normal ms-1"><?= e($positionHints[$position]) ?></small></span>
                <a class="btn btn-sm btn-outline-success" href="<?= e(Uri::admin('view=block&position=' . $position)) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i><?= e(t('BLOCKS_ADD_HERE')) ?></a>
            </div>
            <?php if (!$blocks): ?>
                <div class="card-body text-body-secondary small"><?= e(t($filtered ? 'LIST_NO_MATCH' : 'BLOCKS_EMPTY_POSITION')) ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 py-list">
                        <thead>
                        <tr>
                            <th class="py-col-check"><?php if ($i === 0): ?><?= Grid::checkAll() ?><?php endif ?></th>
                            <th scope="col" class="text-center"><?= e(t('LIST_STATE')) ?></th>
                            <th scope="col"><?= e(t('FIELD_TITLE')) ?></th>
                            <th scope="col" class="d-none d-md-table-cell"><?= e(t('BLOCKS_TYPE')) ?></th>
                            <th scope="col" class="d-none d-lg-table-cell"><?= e(t('BLOCKS_ASSIGNMENT')) ?></th>
                            <th scope="col" class="text-center"><?= e(t('LIST_ORDER')) ?></th>
                            <th scope="col" class="text-end d-none d-md-table-cell">ID</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($blocks as $n => $b): ?>
                            <tr>
                                <td class="py-col-check"><?= Grid::check($i, (int) $b['id'], $b['title']) ?></td>
                                <td class="text-center"><?= Grid::state($i, (bool) $b['state'], 'blocks', t('STATE_PUBLISHED'), t('STATE_UNPUBLISHED')) ?></td>
                                <th scope="row" class="fw-normal"><a href="<?= e(Uri::admin('view=block&id=' . $b['id'])) ?>"><?= e($b['title']) ?></a><?= Grid::access((int) $b['access']) ?></th>
                                <td class="d-none d-md-table-cell small">
                                    <?php if ($b['type'] === 'menu'): ?>
                                        <i class="bi bi-menu-button-wide me-1" aria-hidden="true"></i><?= e(t('BLOCKS_TYPE_MENU')) ?>: <?= e($b['menu_title'] ?? '?') ?>
                                    <?php elseif ($b['type'] === 'search'): ?>
                                        <i class="bi bi-search me-1" aria-hidden="true"></i><?= e(t('BLOCKS_TYPE_SEARCH')) ?>
                                    <?php elseif ($b['type'] === 'user'): ?>
                                        <i class="bi bi-person-circle me-1" aria-hidden="true"></i><?= e(t('BLOCKS_TYPE_USER')) ?>
                                    <?php elseif ($b['type'] === 'hero'): ?>
                                        <i class="bi bi-image me-1" aria-hidden="true"></i><?= e(t('BLOCKS_TYPE_HERO')) ?>
                                    <?php else: ?>
                                        <i class="bi bi-code-square me-1" aria-hidden="true"></i><?= e(t('BLOCKS_TYPE_HTML')) ?>
                                    <?php endif ?>
                                </td>
                                <td class="d-none d-lg-table-cell small"><?= e($assignments[$b['assignment']] ?? '') ?><?= $b['assignment'] !== 'all' ? ' (' . count($b['targets']) . ')' : '' ?></td>
                                <td class="text-center"><?= Grid::order($i, 'blocks', $n === 0, $n === count($blocks) - 1) ?></td>
                                <td class="text-end d-none d-md-table-cell text-body-secondary"><?= (int) $b['id'] ?></td>
                            </tr>
                            <?php $i++ ?>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>
    <?php endforeach ?>
</form>
