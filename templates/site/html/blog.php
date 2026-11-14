<?php
/**
 * Blog layout of a category: cards with thumbnail and intro, then further pages as links.
 * With one column the cards are horizontal (Bootstrap's image-beside-text card).
 * @var string $title
 * @var array $category
 * @var array $params
 * @var list<array> $intro
 * @var list<array> $links
 * @var Pageyard\Pagination $pagination
 * @var Pageyard\Site\Document $doc
 */

use Pageyard\Images;
use Pageyard\Lang;
use Pageyard\Site\Content;
use Pageyard\Site\Html;

defined('PAGEYARD') or exit;

$cols = [1 => 'row-cols-1', 2 => 'row-cols-1 row-cols-md-2', 3 => 'row-cols-1 row-cols-md-2 row-cols-lg-3', 4 => 'row-cols-1 row-cols-sm-2 row-cols-lg-4'][$params['columns']] ?? 'row-cols-1';
$wide = $params['columns'] === 1;
$sizes = [1 => '(min-width: 768px) 33vw, 100vw', 2 => '(min-width: 768px) 50vw, 100vw',
    3 => '(min-width: 992px) 33vw, (min-width: 768px) 50vw, 100vw', 4 => '(min-width: 992px) 25vw, (min-width: 576px) 50vw, 100vw'][$params['columns']] ?? '100vw';
?>
<section class="py-blog">
    <header class="py-blog-header mb-4">
        <h1><?= e($title) ?></h1>
        <?php if ($params['show_description'] && $category['description'] !== null && $pagination->page === 1): ?>
            <div class="py-category-description"><?= Html::content($category['description']) ?></div>
        <?php endif ?>
    </header>

    <?php if (!$intro && !$links): ?>
        <p class="text-body-secondary"><?= e(t('SITE_EMPTY_LIST')) ?></p>
    <?php endif ?>

    <?php if ($intro): ?>
        <div class="row <?= $cols ?> g-4 mb-4">
            <?php foreach ($intro as $item): ?>
                <?php $hasImage = $params['show_image'] && $item['image'] !== '' ?>
                <?php $teaser = Content::teaser($item) ?>
                <div class="col">
                    <article class="card h-100 py-blog-item">
                        <?php if ($wide && $hasImage): ?><div class="row g-0 h-100"><div class="col-md-4"><?php endif ?>
                        <?php if ($hasImage): ?>
                            <?php $attributes = Images::attributes($item['image'], 800, $sizes) ?>
                            <?php if ($item['url'] !== null): ?>
                                <a class="ratio ratio-16x9" href="<?= e($item['url']) ?>" tabindex="-1" aria-hidden="true"><img class="object-fit-cover<?= $wide ? ' rounded-start' : ' card-img-top' ?>"<?= $attributes ?> alt="" loading="lazy" decoding="async"></a>
                            <?php else: ?>
                                <div class="ratio ratio-16x9"><img class="object-fit-cover<?= $wide ? ' rounded-start' : ' card-img-top' ?>"<?= $attributes ?> alt="" loading="lazy" decoding="async"></div>
                            <?php endif ?>
                        <?php endif ?>
                        <?php if ($wide && $hasImage): ?></div><div class="col-md-8"><?php endif ?>
                        <div class="card-body">
                            <h2 class="h5 card-title">
                                <?php if ($item['url'] !== null): ?>
                                    <a href="<?= e($item['url']) ?>"><?= e($item['title']) ?></a>
                                <?php else: ?>
                                    <?= e($item['title']) ?>
                                <?php endif ?>
                            </h2>
                            <?php if ($params['show_date']): ?>
                                <p class="card-subtitle small text-body-secondary mb-2"><time datetime="<?= e(py_date($item['publish_up'], 'c')) ?>"><?= e(Lang::date($item['publish_up'])) ?></time></p>
                            <?php endif ?>
                            <?php if ($teaser['html'] !== ''): ?>
                                <div class="py-blog-intro"><?= $teaser['html'] ?></div>
                            <?php endif ?>
                            <?php if ($wide && $hasImage && $item['url'] !== null && $teaser['more']): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e($item['url']) ?>"><?= e(t('SITE_READ_MORE')) ?><span class="visually-hidden">: <?= e($item['title']) ?></span></a>
                            <?php endif ?>
                        </div>
                        <?php if ($wide && $hasImage): ?></div></div><?php endif ?>
                        <?php if (!($wide && $hasImage) && $item['url'] !== null && $teaser['more']): ?>
                            <div class="card-footer bg-transparent border-0 pt-0 pb-3">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e($item['url']) ?>"><?= e(t('SITE_READ_MORE')) ?><span class="visually-hidden">: <?= e($item['title']) ?></span></a>
                            </div>
                        <?php endif ?>
                    </article>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <?php if ($links): ?>
        <h2 class="h5"><?= e(t('SITE_MORE_PAGES')) ?></h2>
        <ul class="py-blog-links mb-4">
            <?php foreach ($links as $item): ?>
                <li>
                    <?php if ($item['url'] !== null): ?><a href="<?= e($item['url']) ?>"><?= e($item['title']) ?></a><?php else: ?><?= e($item['title']) ?><?php endif ?>
                    <?php if ($params['show_date']): ?><small class="text-body-secondary ms-1"><?= e(Lang::date($item['publish_up'])) ?></small><?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <?= $pagination->render() ?>
</section>
