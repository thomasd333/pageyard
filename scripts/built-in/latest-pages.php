<?php
/**
 * Latest pages of a category as a list of links (in the language of the page being viewed).
 * @param category  category alias (required)
 * @param count     how many pages, 1-50 (default 5)
 * @param dates     show dates: yes/no (default yes)
 * @example category=blog count=5
 */

use Pageyard\Lang;
use Pageyard\Site\Content;
use Pageyard\Site\Router;

defined('PAGEYARD') or exit;

/** @var array<string, string|true> $params */
$lang = Lang::current();
$alias = (string) ($params['category'] ?? '');
$count = max(1, min(50, (int) ($params['count'] ?? 5)));
$dates = ($params['dates'] ?? 'yes') !== 'no';

$categoryId = null;
foreach (Router::categories() as $id => $category) {
    if ($category['state'] && ($category['alias'][$lang] ?? null) === $alias) {
        $categoryId = $id;
        break;
    }
}
if ($categoryId === null) {
    return;  // unknown category: print nothing
}

$pages = Content::categoryPages($categoryId, $lang, 'date_desc', $count, 0)['items'];
if (!$pages) {
    return;
}
?>
<ul class="list-unstyled py-latest-pages">
    <?php foreach ($pages as $page): ?>
        <li class="mb-2">
            <a href="<?= e((string) Router::pageUrl((int) $page['id'], $categoryId, $page['alias'], $lang)) ?>"><?= e($page['title']) ?></a>
            <?php if ($dates): ?><small class="text-body-secondary d-block"><?= e(Lang::date($page['publish_up'])) ?></small><?php endif ?>
        </li>
    <?php endforeach ?>
</ul>
