<?php
/**
 * List layout of a category: one row per page (title and date).
 * @var string $title
 * @var array $category
 * @var array $params
 * @var list<array> $intro all items of this page
 * @var Pageyard\Pagination $pagination
 * @var Pageyard\Site\Document $doc
 */

use Pageyard\Lang;
use Pageyard\Site\Html;

defined('PAGEYARD') or exit;
?>
<section class="py-list">
    <h1 class="mb-3"><?= e($title) ?></h1>
    <?php if ($params['show_description'] && $category['description'] !== null && $pagination->page === 1): ?>
        <div class="py-category-description mb-4"><?= Html::content($category['description']) ?></div>
    <?php endif ?>

    <?php if (!$intro): ?>
        <p class="text-body-secondary"><?= e(t('SITE_EMPTY_LIST')) ?></p>
    <?php else: ?>
        <div class="list-group mb-4">
            <?php foreach ($intro as $item): ?>
                <?php $tag = $item['url'] !== null ? 'a' : 'div' ?>
                <<?= $tag ?> class="list-group-item<?= $tag === 'a' ? ' list-group-item-action' : '' ?> d-flex justify-content-between align-items-center gap-3"<?= $tag === 'a' ? ' href="' . e($item['url']) . '"' : '' ?>>
                    <span><?= e($item['title']) ?></span>
                    <?php if ($params['show_date']): ?>
                        <small class="text-body-secondary text-nowrap"><time datetime="<?= e(py_date($item['publish_up'], 'c')) ?>"><?= e(Lang::date($item['publish_up'])) ?></time></small>
                    <?php endif ?>
                </<?= $tag ?>>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <?= $pagination->render() ?>
</section>
