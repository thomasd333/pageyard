<?php
/**
 * Category: New / Edit. Texts per language on the left; list options (the category's page on the
 * site), state and site URLs on the right.
 * @var array $item
 * @var array<string, string> $errors
 * @var array<string, string> $languages tag => name, default first
 * @var bool $isHome
 * @var array<string, ?string> $urls site URL per language
 */

use Pageyard\Admin\Form;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$isNew = !$item['id'];
$first = array_key_first($languages);
$p = $item['params'];
$number = static fn (string $name, string $label, int $min, int $max) =>
    Form::text($name, $label, $p[$name], $errors, '', 'number', "min=\"$min\" max=\"$max\"");
require __DIR__ . '/../editor.php';
?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes>
    <?= Form::token() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <div class="row g-4">
        <div class="col-xl-8">
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
                                <div class="col-md-4"><?= Form::trText($lang, 'alias', t('FIELD_ALIAS'), $tr['alias'] ?? '', $errors, t('CATEGORIES_ALIAS_HINT'), 'data-slug-target maxlength="190"') ?></div>
                            </div>
                            <?= Form::trArea($lang, 'description', t('CATEGORIES_DESCRIPTION'), $tr['description'] ?? '', $errors, t('CATEGORIES_DESCRIPTION_HINT'), 300) ?>
                            <?= Form::trArea($lang, 'meta_description', t('FIELD_META_DESCRIPTION'), $tr['meta_description'] ?? '', $errors, t('CATEGORIES_META_HINT'), 0, 'rows="2" maxlength="300"') ?>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card py-card mb-4">
                <div class="card-header"><?= e(t('PAGES_SETTINGS')) ?></div>
                <div class="card-body">
                    <?php if ($isHome): ?>
                        <p class="small"><i class="bi bi-house-fill text-warning me-1" aria-hidden="true"></i><?= e(t('HOME_THIS_CATEGORY')) ?></p>
                        <input type="hidden" name="state" value="1">
                    <?php else: ?>
                        <?= Form::toggle('state', t('STATE_PUBLISHED'), (bool) $item['state'], $errors, t('CATEGORIES_STATE_HINT')) ?>
                        <?= Form::select('access', t('ACCESS'), Form::accessOptions(), (int) ($item['access'] ?? 0), $errors, t('ACCESS_CATEGORY_HINT')) ?>
                    <?php endif ?>
                </div>
            </div>

            <div class="card py-card mb-4">
                <div class="card-header"><?= e(t('CATEGORIES_LIST')) ?></div>
                <div class="card-body">
                    <?= Form::select('layout', t('CATEGORIES_LAYOUT'), ['blog' => t('CATEGORIES_LAYOUT_BLOG'), 'list' => t('CATEGORIES_LAYOUT_LIST')], $p['layout'], $errors) ?>
                    <div data-show-if="layout:blog">
                        <div class="row">
                            <div class="col-6"><?= $number('intro_count', t('CATEGORIES_INTRO_COUNT'), 0, 100) ?></div>
                            <div class="col-6"><?= $number('link_count', t('CATEGORIES_LINK_COUNT'), 0, 100) ?></div>
                        </div>
                        <div class="form-text mt-n2 mb-3"><?= e(t('CATEGORIES_COUNTS_HINT')) ?></div>
                        <?= Form::select('columns', t('CATEGORIES_COLUMNS'), [1 => '1', 2 => '2', 3 => '3', 4 => '4'], $p['columns'], $errors) ?>
                        <?= Form::toggle('show_image', t('CATEGORIES_SHOW_IMAGE'), (bool) $p['show_image']) ?>
                    </div>
                    <div data-show-if="layout:list">
                        <?= $number('per_page', t('CATEGORIES_PER_PAGE'), 1, 100) ?>
                    </div>
                    <?= Form::select('order', t('CATEGORIES_ORDER'), [
                        'date_desc' => t('CATEGORIES_ORDER_DATE_DESC'), 'date_asc' => t('CATEGORIES_ORDER_DATE_ASC'),
                        'title_asc' => t('CATEGORIES_ORDER_TITLE_ASC'), 'title_desc' => t('CATEGORIES_ORDER_TITLE_DESC'), 'manual' => t('CATEGORIES_ORDER_MANUAL'),
                    ], $p['order'], $errors) ?>
                    <?= Form::toggle('show_date', t('CATEGORIES_SHOW_DATE'), (bool) $p['show_date']) ?>
                    <?= Form::toggle('show_description', t('CATEGORIES_SHOW_DESCRIPTION'), (bool) $p['show_description']) ?>
                </div>
            </div>

            <?php if (!$isNew): ?>
                <div class="card py-card mb-4">
                    <div class="card-header"><?= e(t('PAGES_ON_SITE')) ?></div>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($urls as $lang => $url): ?>
                            <li class="list-group-item">
                                <span class="badge text-bg-secondary me-1"><?= e(strtoupper($lang)) ?></span>
                                <a href="<?= e((string) $url) ?>" target="_blank" rel="noopener"><?= e((string) $url) ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
                <div class="card py-card">
                    <div class="card-header"><?= e(t('INFO')) ?></div>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between"><span class="text-body-secondary"><?= e(t('INFO_CREATED')) ?></span><span><?= e(py_date($item['created'])) ?></span></li>
                        <li class="list-group-item d-flex justify-content-between"><span class="text-body-secondary"><?= e(t('INFO_MODIFIED')) ?></span><span><?= e(py_date($item['modified'])) ?></span></li>
                        <li class="list-group-item d-flex justify-content-between"><span class="text-body-secondary">ID</span><span><?= (int) $item['id'] ?></span></li>
                    </ul>
                </div>
            <?php endif ?>
        </div>
    </div>
</form>
