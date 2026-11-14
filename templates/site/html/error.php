<?php
/**
 * 404 page. With no home page configured yet it says how to set one up.
 * @var ?string $homeUrl
 * @var bool $noHome
 * @var Pageyard\Site\Document $doc
 */

use Pageyard\Uri;

defined('PAGEYARD') or exit;
?>
<section class="py-error text-center py-5">
    <?php if ($noHome): ?>
        <h1 class="h3"><?= e(t('SITE_NO_HOME_TITLE')) ?></h1>
        <p class="text-body-secondary"><?= e(t('SITE_NO_HOME_TEXT')) ?></p>
        <a class="btn btn-primary" href="<?= e(Uri::admin('view=pages')) ?>"><?= e(t('ADMIN_TITLE')) ?></a>
    <?php else: ?>
        <p class="display-1 fw-bold text-body-tertiary mb-0">404</p>
        <h1 class="h3"><?= e(t('SITE_NOT_FOUND_TITLE')) ?></h1>
        <p class="text-body-secondary"><?= e(t('SITE_NOT_FOUND_TEXT')) ?></p>
        <?php if ($homeUrl !== null): ?>
            <a class="btn btn-primary" href="<?= e($homeUrl) ?>"><?= e(t('SITE_BACK_HOME')) ?></a>
        <?php endif ?>
    <?php endif ?>
</section>
