<?php
/**
 * Menu item: New / Edit.
 * @var array $item
 * @var array<string, string> $errors
 * @var array<int, string> $menus
 * @var array<string, array<int, string>> $parents menu title => [item id => label]
 * @var array<int, string> $pages
 * @var array<int, string> $categories
 * @var bool $hasHome
 * @var array<string, string> $languages tag => name
 */

use Pageyard\Admin\Form;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$first = array_key_first($languages);
$types = ['home' => t('MENUS_TYPE_HOME'), 'page' => t('MENUS_TYPE_PAGE'), 'category' => t('MENUS_TYPE_CATEGORY'), 'url' => t('MENUS_TYPE_URL'),
    'account' => t('MENUS_TYPE_ACCOUNT')];
$parentSelect = static function (array $groups, string $value, array $errors): string {
    $html = '<div class="mb-3"><label class="form-label" for="f-parent_id">' . e(t('MENUS_PARENT')) . '</label>'
        . '<select class="form-select' . (isset($errors['parent_id']) ? ' is-invalid' : '') . '" id="f-parent_id" name="parent_id">'
        . '<option value="">' . e(t('MENUS_NO_PARENT')) . '</option>';
    foreach ($groups as $group => $options) {
        $html .= '<optgroup label="' . e($group) . '">';
        foreach ($options as $id => $text) {
            $html .= '<option value="' . (int) $id . '"' . ((string) $id === $value ? ' selected' : '') . '>' . e($text) . '</option>';
        }
        $html .= '</optgroup>';
    }
    $html .= '</select>';
    if (isset($errors['parent_id'])) {
        $html .= '<div class="invalid-feedback">' . e($errors['parent_id']) . '</div>';
    }
    return $html . '<div class="form-text">' . e(t('MENUS_PARENT_HINT')) . '</div></div>';
};
?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes>
    <?= Form::token() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card py-card">
                <div class="card-body">
                    <?= Form::select('type', t('MENUS_TYPE'), $types, $item['type'], $errors) ?>

                    <div data-show-if="type:home">
                        <p class="form-text mt-n2 mb-3"><?= e(t($hasHome ? 'MENUS_TYPE_HOME_HINT' : 'HOME_NONE')) ?></p>
                    </div>
                    <div data-show-if="type:page">
                        <?= Form::select('page_id', t('MENUS_TYPE_PAGE'), ['' => t('MENUS_CHOOSE')] + $pages, (string) ($item['page_id'] ?? ''), $errors) ?>
                    </div>
                    <div data-show-if="type:category">
                        <?= Form::select('category_id', t('MENUS_TYPE_CATEGORY'), ['' => t('MENUS_CHOOSE')] + $categories, (string) ($item['category_id'] ?? ''), $errors, t('MENUS_CATEGORY_HINT')) ?>
                    </div>
                    <div data-show-if="type:url">
                        <?= Form::text('url', t('MENUS_URL'), (string) $item['url'], $errors, t('MENUS_URL_HINT'), 'text', 'placeholder="https://" maxlength="500"') ?>
                        <?= Form::toggle('new_window', t('MENUS_NEW_WINDOW'), (bool) $item['new_window']) ?>
                    </div>

                    <div data-show-if="type:account">
                        <?= Form::select('account', t('MENUS_TYPE_ACCOUNT'), array_combine(Pageyard\Menus::ACCOUNT_PAGES, array_map(Pageyard\Menus::accountTitle(...), Pageyard\Menus::ACCOUNT_PAGES)),
                            $item['type'] === 'account' ? (string) $item['url'] : '', $errors, t(Pageyard\Accounts::enabled() ? 'MENUS_ACCOUNT_HINT' : 'BLOCKS_USER_OFF')) ?>
                    </div>

                    <fieldset>
                        <legend class="form-label fs-6"><?= e(t('MENUS_LABEL')) ?></legend>
                        <?php foreach ($languages as $lang => $name): ?>
                            <?php $error = $errors["label_$lang"] ?? null ?>
                            <div class="input-group mb-2<?= $error ? ' has-validation' : '' ?>">
                                <span class="input-group-text"><span class="badge text-bg-secondary"><?= e(strtoupper($lang)) ?></span></span>
                                <input class="form-control<?= $error ? ' is-invalid' : '' ?>" type="text" name="label_<?= e($lang) ?>" value="<?= e($item['labels'][$lang] ?? '') ?>"
                                       maxlength="255" aria-label="<?= e(t('MENUS_LABEL') . ' (' . $name . ')') ?>">
                                <?php if ($error): ?><div class="invalid-feedback"><?= e($error) ?></div><?php endif ?>
                            </div>
                        <?php endforeach ?>
                        <div class="form-text" data-hide-if="type:url"><?= e(t('MENUS_LABEL_HINT')) ?></div>
                        <div class="form-text" data-show-if="type:url"><?= e(t('MENUS_LABEL_URL_HINT')) ?></div>
                    </fieldset>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card py-card">
                <div class="card-header"><?= e(t('PAGES_SETTINGS')) ?></div>
                <div class="card-body">
                    <?= Form::select('menu_id', t('MENUS_MENU'), $menus, (string) $item['menu_id'], $errors) ?>
                    <?= $parentSelect($parents, (string) ($item['parent_id'] ?: ''), $errors) ?>
                    <?= Form::toggle('state', t('STATE_PUBLISHED'), (bool) $item['state'], $errors) ?>
                    <?= Form::select('access', t('ACCESS'), Pageyard\Admin\Form::accessOptions(true), (int) ($item['access'] ?? 0), $errors, t('ACCESS_MENU_HINT')) ?>
                </div>
            </div>
        </div>
    </div>
</form>
