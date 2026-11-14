<?php
/**
 * Block: New / Edit.
 * @var array $item
 * @var array<string, string> $errors
 * @var array<int, string> $menus
 * @var array<int, string> $pages
 * @var array<int, string> $categories
 * @var array<string, string> $languages tag => name, default first
 */

use Pageyard\Admin\Form;
use Pageyard\Blocks;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$isNew = !$item['id'];
$first = array_key_first($languages);
$selected = $item['targets'];
$hero = Blocks::params($item);
$image = (string) ($item['image'] ?? '');
$groups = [
    t('BLOCKS_GROUP_HOME')       => ['home' => t('HOME_IS')],
    t('CATEGORIES_TITLE')        => array_combine(array_map(fn ($id) => "category:$id", array_keys($categories)), $categories) ?: [],
    t('PAGES_TITLE')             => array_combine(array_map(fn ($id) => "page:$id", array_keys($pages)), $pages) ?: [],
];
require __DIR__ . '/../editor.php';
?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes<?= $isNew ? ' data-new-block' : '' ?>>
    <?= Form::token() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card py-card mb-4">
                <div class="card-body">
                    <?= Form::text('title', t('BLOCKS_NAME'), $item['title'], $errors, t('BLOCKS_NAME_HINT'), 'text', 'required maxlength="255"' . ($isNew ? ' autofocus' : '')) ?>
                    <div class="row">
                        <div class="col-md-6"><?= Form::select('type', t('BLOCKS_TYPE'), ['html' => t('BLOCKS_TYPE_HTML'), 'menu' => t('BLOCKS_TYPE_MENU'), 'hero' => t('BLOCKS_TYPE_HERO'), 'search' => t('BLOCKS_TYPE_SEARCH'), 'user' => t('BLOCKS_TYPE_USER')], $item['type'], $errors) ?></div>
                        <div class="col-md-6" data-show-if="type:menu">
                            <?= Form::select('menu_id', t('MENUS_MENU'), ['' => t('MENUS_CHOOSE')] + $menus, (string) ($item['menu_id'] ?? ''), $errors, $menus ? '' : t('BLOCKS_NO_MENUS')) ?>
                        </div>
                        <div class="col-md-6 form-text" data-show-if="type:user">
                            <?= e(t(Pageyard\Accounts::enabled() ? 'BLOCKS_USER_HINT' : 'BLOCKS_USER_OFF')) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card py-card mb-4" data-show-if="type:hero">
                <div class="card-header"><?= e(t('HERO_SECTION')) ?></div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <span class="form-label d-block"><?= e(t('HERO_IMAGE')) ?></span>
                            <div data-image-field data-folder="hero">
                                <div class="py-image-preview mb-2<?= $image === '' ? ' d-none' : '' ?>">
                                    <img src="<?= $image !== '' ? e(Uri::root() . $image) : '' ?>" alt="">
                                </div>
                                <div class="d-flex gap-2 mb-2">
                                    <label class="btn btn-sm btn-outline-primary mb-0">
                                        <i class="bi bi-upload me-1" aria-hidden="true"></i><?= e(t('PAGES_IMAGE_UPLOAD')) ?>
                                        <input type="file" class="d-none" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" data-image-file>
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-danger<?= $image === '' ? ' d-none' : '' ?>" data-image-remove>
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i><?= e(t('PAGES_IMAGE_REMOVE')) ?>
                                    </button>
                                </div>
                                <input class="form-control form-control-sm<?= isset($errors['image']) ? ' is-invalid' : '' ?>" type="text" name="image" value="<?= e($image) ?>"
                                       placeholder="images/..." aria-label="<?= e(t('PAGES_IMAGE_PATH')) ?>" data-image-path>
                                <?php if (isset($errors['image'])): ?><div class="invalid-feedback"><?= e($errors['image']) ?></div><?php endif ?>
                                <div class="form-text"><?= e(t('HERO_IMAGE_HINT')) ?></div>
                                <div class="alert alert-danger py-2 small mt-2 d-none" role="alert" data-image-error></div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <?= Form::select('hero_height', t('HERO_HEIGHT'), ['sm' => t('HERO_HEIGHT_SM'), 'md' => t('HERO_HEIGHT_MD'), 'lg' => t('HERO_HEIGHT_LG'), 'full' => t('HERO_HEIGHT_FULL')], $hero['height']) ?>
                            <?= Form::select('hero_align', t('HERO_ALIGN'), ['start' => t('HERO_ALIGN_START'), 'center' => t('HERO_ALIGN_CENTER'), 'end' => t('HERO_ALIGN_END')], $hero['align']) ?>
                            <?= Form::select('hero_overlay', t('HERO_OVERLAY'), [0 => t('HERO_OVERLAY_NONE'), 25 => t('HERO_OVERLAY_25'), 50 => t('HERO_OVERLAY_50'), 75 => t('HERO_OVERLAY_75')], $hero['overlay'], [], t('HERO_OVERLAY_HINT')) ?>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <?= Form::select('link_type', t('HERO_LINK'), ['' => t('HERO_LINK_NONE'), 'home' => t('MENUS_TYPE_HOME'), 'page' => t('MENUS_TYPE_PAGE'), 'category' => t('MENUS_TYPE_CATEGORY'), 'url' => t('MENUS_TYPE_URL')], $hero['link_type']) ?>
                        </div>
                        <div class="col-md-6" data-show-if="link_type:page">
                            <?= Form::select('link_page', t('MENUS_TYPE_PAGE'), ['' => t('MENUS_CHOOSE')] + $pages, (string) ($hero['link_page'] ?: ''), $errors) ?>
                        </div>
                        <div class="col-md-6" data-show-if="link_type:category">
                            <?= Form::select('link_category', t('MENUS_TYPE_CATEGORY'), ['' => t('MENUS_CHOOSE')] + $categories, (string) ($hero['link_category'] ?: ''), $errors) ?>
                        </div>
                        <div class="col-md-6" data-show-if="link_type:url">
                            <?= Form::text('link_url', t('MENUS_URL'), (string) $hero['link_url'], $errors, t('MENUS_URL_HINT'), 'text', 'placeholder="https://" maxlength="500"') ?>
                            <?= Form::toggle('link_new_window', t('MENUS_NEW_WINDOW'), (bool) $hero['new_window']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <?= Form::langTabs($languages, $errors) ?>
            <div class="card py-card<?= count($languages) > 1 ? ' py-card-tabbed' : '' ?>">
                <div class="card-body tab-content">
                    <?php foreach ($languages as $lang => $name): ?>
                        <?php $tr = $item['translations'][$lang] ?? [] ?>
                        <div class="tab-pane fade<?= $lang === $first ? ' show active' : '' ?>" id="pane-lang-<?= e($lang) ?>" role="tabpanel" aria-labelledby="tab-lang-<?= e($lang) ?>">
                            <?= Form::trText($lang, 'title', t('BLOCKS_HEADING'), $tr['title'] ?? '', $errors, t('BLOCKS_HEADING_HINT'), 'maxlength="255"') ?>
                            <div data-show-if="type:html">
                                <?= Form::trArea($lang, 'content', t('BLOCKS_CONTENT'), $tr['content'] ?? '', $errors, $lang === $first ? '' : t('BLOCKS_CONTENT_LANG_HINT'), 320) ?>
                            </div>
                            <div data-show-if="type:hero">
                                <?= Form::trArea($lang, 'text', t('HERO_TEXT'), $tr['text'] ?? (($item['type'] === 'hero' ? $tr['content'] ?? '' : '')), $errors, $lang === $first ? '' : t('HERO_TEXT_LANG_HINT'), 0, 'rows="3"') ?>
                                <?= Form::trText($lang, 'link_label', t('HERO_LINK_LABEL'), $tr['link_label'] ?? '', $errors, t('HERO_LINK_LABEL_HINT'), 'maxlength="255"') ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card py-card mb-4">
                <div class="card-header"><?= e(t('PAGES_SETTINGS')) ?></div>
                <div class="card-body">
                    <div data-hide-if="type:hero">
                        <?= Form::select('position', t('BLOCKS_POSITION'), ['top' => t('BLOCKS_POS_TOP'), 'hero' => t('BLOCKS_POS_HERO'), 'left' => t('BLOCKS_POS_LEFT'), 'right' => t('BLOCKS_POS_RIGHT'), 'footer' => t('BLOCKS_POS_FOOTER')], $item['position'], $errors) ?>
                    </div>
                    <?= Form::toggle('state', t('STATE_PUBLISHED'), (bool) $item['state'], $errors) ?>
                    <?= Form::select('access', t('ACCESS'), Form::accessOptions(true), (int) ($item['access'] ?? 0), $errors, t('ACCESS_BLOCK_HINT')) ?>
                    <div data-hide-if="type:hero">
                        <?= Form::toggle('show_title', t('BLOCKS_SHOW_TITLE'), (bool) $item['show_title'], $errors, t('BLOCKS_SHOW_TITLE_HINT')) ?>
                    </div>
                    <?= Form::text('css_class', t('BLOCKS_CSS_CLASS'), $item['css_class'], $errors, t('BLOCKS_CSS_CLASS_HINT'), 'text', 'maxlength="100"') ?>
                </div>
            </div>

            <div class="card py-card">
                <div class="card-header"><?= e(t('BLOCKS_ASSIGNMENT')) ?></div>
                <div class="card-body">
                    <?php foreach (['all' => t('BLOCKS_ASSIGN_ALL'), 'only' => t('BLOCKS_ASSIGN_ONLY'), 'except' => t('BLOCKS_ASSIGN_EXCEPT')] as $value => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="assignment" id="f-assign-<?= $value ?>" value="<?= $value ?>"<?= $item['assignment'] === $value ? ' checked' : '' ?>>
                            <label class="form-check-label" for="f-assign-<?= $value ?>"><?= e($label) ?></label>
                        </div>
                    <?php endforeach ?>
                    <div class="mt-3" data-show-if="assignment:only,except">
                        <?php if (isset($errors['targets'])): ?><div class="text-danger small mb-2"><?= e($errors['targets']) ?></div><?php endif ?>
                        <div class="py-checklist border rounded p-2">
                            <?php foreach ($groups as $group => $options): ?>
                                <?php if (!$options) continue; ?>
                                <div class="small fw-semibold text-body-secondary mt-1"><?= e($group) ?></div>
                                <?php foreach ($options as $value => $label): ?>
                                    <?php $fid = 'f-target-' . str_replace(':', '-', $value) ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="targets[]" id="<?= e($fid) ?>" value="<?= e($value) ?>"<?= in_array($value, $selected, true) ? ' checked' : '' ?>>
                                        <label class="form-check-label small" for="<?= e($fid) ?>"><?= e($label) ?></label>
                                    </div>
                                <?php endforeach ?>
                            <?php endforeach ?>
                        </div>
                        <div class="form-text"><?= e(t('BLOCKS_ASSIGN_HINT')) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
