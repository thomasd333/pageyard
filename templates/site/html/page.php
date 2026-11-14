<?php
/**
 * A single page: title, date, image, intro, main text.
 * @var array $page
 * @var Pageyard\Site\Document $doc
 */

use Pageyard\Images;
use Pageyard\Lang;
use Pageyard\Site\Html;

defined('PAGEYARD') or exit;
?>
<article class="py-page">
    <header class="mb-4">
        <h1 class="py-page-title"><?= e($page['title']) ?></h1>
        <?php if (!$doc->isHome): ?>
            <p class="py-page-meta small text-body-secondary mb-0"><time datetime="<?= e(py_date($page['publish_up'], 'c')) ?>"><?= e(Lang::date($page['publish_up'])) ?></time></p>
        <?php endif ?>
    </header>
    <?php if ($page['image'] !== ''): ?>
        <figure class="py-page-image mb-4"><img class="img-fluid rounded"<?= Images::attributes($page['image'], 1600, '(min-width: 1400px) 1320px, 100vw') ?> alt="" fetchpriority="high"></figure>
    <?php endif ?>
    <?php if ($page['intro'] !== null): ?>
        <div class="py-page-intro"><?= Html::content($page['intro']) ?></div>
    <?php endif ?>
    <?php if ($page['body'] !== null): ?>
        <div class="py-page-body"><?= Html::content($page['body']) ?></div>
    <?php endif ?>
</article>
