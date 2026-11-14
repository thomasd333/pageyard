<?php
/**
 * Redirect: New / Edit.
 * @var array $item
 * @var array<string, string> $errors
 * @var array<int, string> $pages
 * @var array<int, string> $categories
 */

use Pageyard\Admin\Form;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$types = ['page' => t('MENUS_TYPE_PAGE'), 'category' => t('MENUS_TYPE_CATEGORY'), 'url' => t('MENUS_TYPE_URL')];
$type = isset($types[$item['target_type']]) ? $item['target_type'] : 'url';
?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes>
    <?= Form::token() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <div class="row">
        <div class="col-lg-7">
            <div class="card py-card">
                <div class="card-body">
                    <?= Form::text('from', t('REDIRECTS_FROM'), $item['from'], $errors, t('REDIRECTS_FROM_HINT'), 'text', 'required maxlength="500"' . ($item['id'] ? '' : ' autofocus')) ?>
                    <?= Form::select('target_type', t('REDIRECTS_TO'), $types, $type, $errors) ?>
                    <div data-show-if="target_type:page">
                        <?= Form::select('page_id', t('MENUS_TYPE_PAGE'), ['' => t('MENUS_CHOOSE')] + $pages, $type === 'page' ? (string) $item['target_id'] : '', $errors) ?>
                    </div>
                    <div data-show-if="target_type:category">
                        <?= Form::select('category_id', t('MENUS_TYPE_CATEGORY'), ['' => t('MENUS_CHOOSE')] + $categories, $type === 'category' ? (string) $item['target_id'] : '', $errors) ?>
                    </div>
                    <div data-show-if="target_type:url">
                        <?= Form::text('url', t('MENUS_URL'), $item['url'], $errors, t('REDIRECTS_URL_HINT'), 'text', 'maxlength="500"') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
