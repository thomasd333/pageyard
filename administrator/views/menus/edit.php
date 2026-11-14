<?php
/**
 * Menu: New / Edit (only the name).
 * @var array $item
 * @var array<string, string> $errors
 */

use Pageyard\Admin\Form;
use Pageyard\Uri;

defined('PAGEYARD') or exit;
?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes>
    <?= Form::token() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <div class="row">
        <div class="col-lg-6">
            <div class="card py-card">
                <div class="card-body">
                    <?= Form::text('title', t('FIELD_TITLE'), $item['title'], $errors, t('MENUS_TITLE_HINT'), 'text', 'required maxlength="100"' . ($item['id'] ? '' : ' autofocus')) ?>
                    <?php if ($item['id']): ?>
                        <a href="<?= e(Uri::admin('view=menuitems&menu=' . $item['id'])) ?>"><i class="bi bi-list-nested me-1" aria-hidden="true"></i><?= e(t('MENUS_MANAGE_ITEMS')) ?></a>
                    <?php endif ?>
                </div>
            </div>
        </div>
    </div>
</form>
