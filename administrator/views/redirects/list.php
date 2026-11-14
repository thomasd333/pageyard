<?php
/**
 * Redirects: Manage.
 * @var array $state
 * @var list<array> $items
 * @var Pageyard\Pagination $pagination
 * @var int $limit
 */

use Pageyard\Admin\Form;
use Pageyard\Admin\Grid;
use Pageyard\Config;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$filtered = $state['search'] !== '' || $state['kind'] !== '';
$default = (string) Config::get('language');
?>
<p class="text-body-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e(t('REDIRECTS_INTRO')) ?></p>

<form class="py-filters" method="get" action="<?= e(Uri::admin()) ?>">
    <input type="hidden" name="view" value="redirects">
    <?= Grid::search($state['search'], t('REDIRECTS_SEARCH_HINT')) ?>
    <?= Grid::filter('kind', t('REDIRECTS_KIND'), ['auto' => t('REDIRECTS_AUTO'), 'manual' => t('REDIRECTS_MANUAL')], $state['kind']) ?>
    <?= $filtered ? Grid::clear('redirects') : '' ?>
    <?= Grid::limit($limit) ?>
</form>

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
                        <th scope="col"><?= Grid::sort('redirects', $state, 'path', t('REDIRECTS_FROM')) ?></th>
                        <th scope="col"><?= e(t('REDIRECTS_TO')) ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?= e(t('REDIRECTS_KIND')) ?></th>
                        <th scope="col" class="text-end"><?= Grid::sort('redirects', $state, 'hits', t('REDIRECTS_HITS')) ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?= Grid::sort('redirects', $state, 'last_hit', t('REDIRECTS_LAST_HIT')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $i => $r): ?>
                        <?php
                        $shown = '/' . ($r['lang'] !== $default ? $r['lang'] . '/' : '') . $r['path'];
                        $title = $r['target_type'] === 'page' ? $r['page_title'] : $r['category_title'];
                        ?>
                        <tr>
                            <td class="py-col-check"><?= Grid::check($i, (int) $r['id'], $shown) ?></td>
                            <th scope="row" class="fw-normal text-break"><a href="<?= e(Uri::admin('view=redirect&id=' . $r['id'])) ?>"><?= e($shown) ?></a></th>
                            <td class="text-break">
                                <?php if ($r['target_type'] === 'url'): ?>
                                    <i class="bi bi-link-45deg me-1" aria-hidden="true"></i><?= e(preg_match('~^https?://~i', $r['url']) ? $r['url'] : '/' . $r['url']) ?>
                                <?php elseif ($title === null): ?>
                                    <span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= e(t('REDIRECTS_TARGET_GONE')) ?></span>
                                <?php elseif ($r['target_type'] === 'page'): ?>
                                    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i><a href="<?= e(Uri::admin('view=page&id=' . $r['target_id'])) ?>"><?= e($title) ?></a>
                                <?php else: ?>
                                    <i class="bi bi-folder me-1" aria-hidden="true"></i><a href="<?= e(Uri::admin('view=category&id=' . $r['target_id'])) ?>"><?= e($title) ?></a>
                                <?php endif ?>
                            </td>
                            <td class="d-none d-md-table-cell small text-body-secondary"><?= e(t($r['auto'] ? 'REDIRECTS_AUTO' : 'REDIRECTS_MANUAL')) ?></td>
                            <td class="text-end"><?= (int) $r['hits'] ?></td>
                            <td class="d-none d-lg-table-cell small text-body-secondary"><?= $r['last_hit'] !== null ? e(py_date($r['last_hit'])) : '-' ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif ?>
    <?= Grid::footer($pagination) ?>
</form>
