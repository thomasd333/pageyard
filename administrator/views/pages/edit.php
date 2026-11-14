<?php
/**
 * Page: New / Edit. Texts per language in tabs, shared settings on the right.
 * @var array $item
 * @var array<string, string> $errors
 * @var array<string, string> $languages tag => name, default first
 * @var array<int, string> $categories
 * @var int $maxUpload MB
 * @var array<string, ?string> $urls site URL per language (null = not reachable)
 */

use Pageyard\Admin\Form;
use Pageyard\Pages;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$isNew = !$item['id'];
$first = array_key_first($languages);
require __DIR__ . '/../editor.php';
?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes>
    <?= Form::token() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <div class="row g-4">
        <div class="col-xl-9">
            <?= Form::langTabs($languages, $errors) ?>
            <div class="card py-card<?= count($languages) > 1 ? ' py-card-tabbed' : '' ?>">
                <div class="card-body tab-content">
                    <?php foreach ($languages as $lang => $name): ?>
                        <?php $tr = $item['translations'][$lang] ?? [] ?>
                        <div class="tab-pane fade<?= $lang === $first ? ' show active' : '' ?>" id="pane-lang-<?= e($lang) ?>" role="tabpanel" aria-labelledby="tab-lang-<?= e($lang) ?>">
                            <?php if ($lang !== $first && empty($tr['title'])): ?>
                                <p class="small text-body-secondary"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e(t('TRANSLATION_OPTIONAL')) ?></p>
                            <?php endif ?>
                            <div class="row">
                                <div class="col-md-8"><?= Form::trText($lang, 'title', t('FIELD_TITLE'), $tr['title'] ?? '', $errors, '', 'data-slug-source maxlength="255"' . ($isNew && $lang === $first ? ' autofocus' : '')) ?></div>
                                <div class="col-md-4"><?= Form::trText($lang, 'alias', t('FIELD_ALIAS'), $tr['alias'] ?? '', $errors, t('FIELD_ALIAS_HINT'), 'data-slug-target maxlength="190"') ?></div>
                            </div>
                            <?= Form::trArea($lang, 'text', t('PAGES_TEXT'), Pages::joinText($tr['intro'] ?? null, $tr['body'] ?? null), $errors, t('PAGES_TEXT_HINT'), 600, 'data-readmore') ?>
                            <?= Form::trArea($lang, 'meta_description', t('FIELD_META_DESCRIPTION'), $tr['meta_description'] ?? '', $errors, t('FIELD_META_DESCRIPTION_HINT'), 0, 'rows="2" maxlength="300"') ?>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>

        <div class="col-xl-3">
            <div class="card py-card mb-4">
                <div class="card-header"><?= e(t('PAGES_SETTINGS')) ?></div>
                <div class="card-body">
                    <?= Form::toggle('state', t('STATE_PUBLISHED'), (bool) $item['state'], $errors) ?>
                    <?= Form::select('access', t('ACCESS'), Form::accessOptions(), (int) ($item['access'] ?? 0), $errors, t('ACCESS_PAGE_HINT')) ?>
                    <?= Form::select('category_id', t('PAGES_CATEGORY'), ['' => t('PAGES_NO_CATEGORY')] + $categories, (string) ($item['category_id'] ?? ''), $errors,
                        $categories ? '' : t('PAGES_NO_CATEGORIES_YET')) ?>
                    <?= Form::text('publish_up', t('PAGES_DATE'), $item['publish_up_local'], $errors, t('PAGES_DATE_HINT'), 'datetime-local') ?>
                </div>
            </div>

            <div class="card py-card mb-4">
                <div class="card-header"><?= e(t('PAGES_IMAGE')) ?></div>
                <div class="card-body" data-image-field data-folder="pages">
                    <div class="py-image-preview mb-3<?= $item['image'] === '' ? ' d-none' : '' ?>">
                        <img src="<?= $item['image'] !== '' ? e(Uri::root() . $item['image']) : '' ?>" alt="">
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <label class="btn btn-sm btn-outline-primary mb-0">
                            <i class="bi bi-upload me-1" aria-hidden="true"></i><?= e(t('PAGES_IMAGE_UPLOAD')) ?>
                            <input type="file" class="d-none" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" data-image-file>
                        </label>
                        <button type="button" class="btn btn-sm btn-outline-danger<?= $item['image'] === '' ? ' d-none' : '' ?>" data-image-remove>
                            <i class="bi bi-trash me-1" aria-hidden="true"></i><?= e(t('PAGES_IMAGE_REMOVE')) ?>
                        </button>
                    </div>
                    <input class="form-control form-control-sm<?= isset($errors['image']) ? ' is-invalid' : '' ?>" type="text" name="image" id="f-image"
                           value="<?= e($item['image']) ?>" placeholder="images/..." aria-label="<?= e(t('PAGES_IMAGE_PATH')) ?>" data-image-path>
                    <?php if (isset($errors['image'])): ?><div class="invalid-feedback"><?= e($errors['image']) ?></div><?php endif ?>
                    <div class="form-text"><?= e(t('PAGES_IMAGE_HINT', $maxUpload)) ?></div>
                    <div class="alert alert-danger py-2 small mt-2 d-none" role="alert" data-image-error></div>
                </div>
            </div>

            <?php if (!$isNew): ?>
                <div class="card py-card mb-4">
                    <div class="card-header"><?= e(t('PAGES_ON_SITE')) ?></div>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($urls as $lang => $url): ?>
                            <li class="list-group-item">
                                <span class="badge text-bg-secondary me-1"><?= e(strtoupper($lang)) ?></span>
                                <?php if ($url !== null): ?>
                                    <a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                                <?php else: ?>
                                    <span class="text-body-secondary"><?= e(t('PAGES_NO_URL')) ?></span>
                                <?php endif ?>
                                <a class="d-block mt-1" href="<?= e(Pageyard\Admin\Controllers\PagesController::previewUrl((int) $item['id'], $lang)) ?>" target="_blank" rel="noopener">
                                    <i class="bi bi-eye me-1" aria-hidden="true"></i><?= e(t('TOOLBAR_PREVIEW')) ?></a>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
                <div class="card py-card">
                    <div class="card-header"><?= e(t('INFO')) ?></div>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item"><span class="text-body-secondary d-block"><?= e(t('INFO_CREATED')) ?></span><?= e(py_date($item['created'])) ?><?= $item['created_by_name'] ? ' &middot; ' . e($item['created_by_name']) : '' ?></li>
                        <li class="list-group-item"><span class="text-body-secondary d-block"><?= e(t('INFO_MODIFIED')) ?></span><?= e(py_date($item['modified'])) ?><?= $item['modified_by_name'] ? ' &middot; ' . e($item['modified_by_name']) : '' ?></li>
                        <li class="list-group-item d-flex justify-content-between"><span class="text-body-secondary">ID</span><span><?= (int) $item['id'] ?></span></li>
                    </ul>
                </div>
            <?php endif ?>
        </div>
    </div>
</form>
