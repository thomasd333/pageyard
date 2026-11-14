<?php
/**
 * Search results.
 * @var string $query
 * @var string $form the search box
 * @var bool $tooShort
 * @var list<array{title: string, url: string, snippet: string}> $results this page's
 * @var int $total
 * @var Pageyard\Pagination $pagination
 * @var Pageyard\Site\Document $doc
 */

defined('PAGEYARD') or exit;
?>
<section class="py-search-results">
    <h1 class="mb-4"><?= e(t('SEARCH_TITLE')) ?></h1>
    <div class="mb-4"><?= $form ?></div>

    <?php if ($tooShort): ?>
        <p class="text-body-secondary"><?= e(t('SEARCH_TOO_SHORT')) ?></p>
    <?php elseif ($query !== ''): ?>
        <?php if (!$results): ?>
            <p class="text-body-secondary"><?= e(t('SEARCH_NONE', $query)) ?></p>
        <?php else: ?>
            <p class="text-body-secondary"><?= e(t('SEARCH_COUNT', $total)) ?></p>
            <?php foreach ($results as $result): ?>
                <article class="mb-4">
                    <h2 class="h5 mb-1"><a href="<?= e($result['url']) ?>"><?= e($result['title']) ?></a></h2>
                    <?php if ($result['snippet'] !== ''): ?>
                        <p class="mb-0 small"><?= $result['snippet'] /* escaped and marked in Search::snippet */ ?></p>
                    <?php endif ?>
                </article>
            <?php endforeach ?>
            <?= $pagination->render() ?>
        <?php endif ?>
    <?php endif ?>
</section>
