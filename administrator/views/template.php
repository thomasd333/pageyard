<?php
/**
 * System > Template: layout, look and custom CSS of the site template.
 * @var array $o template options
 * @var string $css
 * @var array<string, string> $errors
 * @var string $open the tab to show
 * @var list<string> $errorTabs tabs with errors
 * @var int $maxUpload MB
 * @var list<array{path: string, replaces: bool, newer: bool}> $files in templates/custom/
 * @var array{head: string, body: string} $code
 */

use Pageyard\Admin\Form;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

require __DIR__ . '/editor.php';
$tab = static fn (string $id, string $icon, string $label) =>
    '<li class="nav-item" role="presentation"><button class="nav-link' . ($open === $id ? ' active' : '') . (in_array($id, $errorTabs, true) ? ' text-danger' : '') . '" id="tab-' . $id
    . '" data-bs-toggle="tab" data-bs-target="#pane-' . $id . '" type="button" role="tab" aria-controls="pane-' . $id
    . '" aria-selected="' . ($open === $id ? 'true' : 'false') . '"><i class="bi ' . $icon . ' me-2" aria-hidden="true"></i>' . e($label)
    . (in_array($id, $errorTabs, true) ? ' <i class="bi bi-exclamation-circle-fill ms-1" aria-hidden="true"></i>' : '') . '</button></li>';
$pane = static fn (string $id): string => 'tab-pane fade' . ($open === $id ? ' show active' : '');
$widths = [2 => t('TEMPLATE_WIDTH_NARROW'), 3 => t('TEMPLATE_WIDTH_MEDIUM'), 4 => t('TEMPLATE_WIDTH_WIDE')];
?>
<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>" novalidate data-track-changes>
    <?= Form::token() ?>
    <input type="hidden" name="tab" value="<?= e($open) ?>">
    <ul class="nav nav-tabs py-tabs" role="tablist" data-remember-tab>
        <?= $tab('layout', 'bi-layout-three-columns', t('TEMPLATE_TAB_LAYOUT')) ?>
        <?= $tab('look', 'bi-brush', t('TEMPLATE_TAB_LOOK')) ?>
        <?= $tab('css', 'bi-filetype-css', t('TEMPLATE_TAB_CSS')) ?>
        <?= $tab('code', 'bi-code-slash', t('TEMPLATE_TAB_CODE')) ?>
        <?= $tab('files', 'bi-files', t('TEMPLATE_TAB_FILES')) ?>
    </ul>
    <div class="card py-card py-card-tabbed">
        <div class="card-body tab-content">
            <div class="<?= $pane('layout') ?>" id="pane-layout" role="tabpanel" aria-labelledby="tab-layout">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="py-layout-preview mb-3" aria-hidden="true">
                            <div class="py-lp-top"><?= e(t('BLOCKS_POS_TOP')) ?></div>
                            <div><?= e(t('BLOCKS_POS_HERO')) ?></div>
                            <div class="py-lp-middle">
                                <div class="py-lp-side" data-show-if="show_left:1"><span data-hide-if="layout:sidebar"><?= e(t('BLOCKS_POS_LEFT')) ?></span><span data-show-if="layout:sidebar"><?= e(t('TEMPLATE_SIDEBAR')) ?></span></div>
                                <div class="py-lp-content"><?= e(t('TEMPLATE_CONTENT')) ?></div>
                                <div class="py-lp-side" data-show-if="show_right:1"><?= e(t('BLOCKS_POS_RIGHT')) ?></div>
                            </div>
                            <div class="py-lp-footer"><?= e(t('BLOCKS_POS_FOOTER')) ?></div>
                        </div>
                        <p class="small text-body-secondary"><?= e(t('TEMPLATE_LAYOUT_HINT')) ?> <a href="<?= e(Uri::admin('view=blocks')) ?>"><?= e(t('BLOCKS_TITLE')) ?></a></p>
                    </div>
                    <div class="col-lg-5">
                        <?= Form::select('layout', t('TEMPLATE_LAYOUT'), ['panels' => t('TEMPLATE_LAYOUT_PANELS'), 'sidebar' => t('TEMPLATE_LAYOUT_SIDEBAR')], $o['layout']) ?>
                        <div class="form-text mt-n2 mb-3" data-show-if="layout:sidebar"><?= e(t('TEMPLATE_LAYOUT_SIDEBAR_HINT')) ?></div>
                        <div class="row">
                            <div class="col-6"><?= Form::toggle('show_left', t('TEMPLATE_SHOW_LEFT'), (bool) $o['show_left']) ?></div>
                            <div class="col-6"><?= Form::toggle('show_right', t('TEMPLATE_SHOW_RIGHT'), (bool) $o['show_right']) ?></div>
                            <div class="col-6"><?= Form::select('left_width', t('TEMPLATE_LEFT_WIDTH'), $widths, $o['left_width']) ?></div>
                            <div class="col-6"><?= Form::select('right_width', t('TEMPLATE_RIGHT_WIDTH'), $widths, $o['right_width']) ?></div>
                        </div>
                        <div data-hide-if="layout:sidebar">
                            <?= Form::select('container', t('TEMPLATE_CONTAINER'), ['container' => t('TEMPLATE_CONTAINER_FIXED'), 'container-fluid' => t('TEMPLATE_CONTAINER_FLUID')], $o['container']) ?>
                        </div>
                        <?= Form::toggle('show_copyright', t('TEMPLATE_SHOW_COPYRIGHT'), (bool) $o['show_copyright']) ?>
                    </div>
                </div>
            </div>

            <div class="<?= $pane('look') ?>" id="pane-look" role="tabpanel" aria-labelledby="tab-look">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <?= Form::select('color_mode', t('TEMPLATE_COLOR_MODE'), ['light' => t('TEMPLATE_MODE_LIGHT'), 'dark' => t('TEMPLATE_MODE_DARK'), 'auto' => t('TEMPLATE_MODE_AUTO')], $o['color_mode'], [], t('TEMPLATE_COLOR_MODE_HINT')) ?>
                        <?= Form::select('navbar', t('TEMPLATE_NAVBAR'), ['dark' => t('TEMPLATE_NAVBAR_DARK'), 'light' => t('TEMPLATE_NAVBAR_LIGHT'), 'primary' => t('TEMPLATE_NAVBAR_PRIMARY')], $o['navbar']) ?>
                        <?= Form::toggle('navbar_sticky', t('TEMPLATE_NAVBAR_STICKY'), (bool) $o['navbar_sticky'], [], t('TEMPLATE_NAVBAR_STICKY_HINT')) ?>
                    </div>
                    <div class="col-lg-6">
                        <span class="form-label d-block"><?= e(t('TEMPLATE_LOGO')) ?></span>
                        <div data-image-field data-folder="site">
                            <div class="py-image-preview py-logo-preview mb-2<?= $o['logo'] === '' ? ' d-none' : '' ?>">
                                <img src="<?= $o['logo'] !== '' ? e(Uri::root() . $o['logo']) : '' ?>" alt="">
                            </div>
                            <div class="d-flex gap-2 mb-2">
                                <label class="btn btn-sm btn-outline-primary mb-0">
                                    <i class="bi bi-upload me-1" aria-hidden="true"></i><?= e(t('PAGES_IMAGE_UPLOAD')) ?>
                                    <input type="file" class="d-none" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" data-image-file>
                                </label>
                                <button type="button" class="btn btn-sm btn-outline-danger<?= $o['logo'] === '' ? ' d-none' : '' ?>" data-image-remove>
                                    <i class="bi bi-trash me-1" aria-hidden="true"></i><?= e(t('PAGES_IMAGE_REMOVE')) ?>
                                </button>
                            </div>
                            <input class="form-control form-control-sm<?= isset($errors['logo']) ? ' is-invalid' : '' ?>" type="text" name="logo" value="<?= e($o['logo']) ?>"
                                   placeholder="images/..." aria-label="<?= e(t('PAGES_IMAGE_PATH')) ?>" data-image-path>
                            <?php if (isset($errors['logo'])): ?><div class="invalid-feedback"><?= e($errors['logo']) ?></div><?php endif ?>
                            <div class="form-text mb-3"><?= e(t('TEMPLATE_LOGO_HINT', $maxUpload)) ?></div>
                            <div class="alert alert-danger py-2 small d-none" role="alert" data-image-error></div>
                        </div>
                        <?= Form::toggle('show_sitename', t('TEMPLATE_SHOW_SITENAME'), (bool) $o['show_sitename'], [], t('TEMPLATE_SHOW_SITENAME_HINT')) ?>
                    </div>
                </div>
            </div>

            <div class="<?= $pane('css') ?>" id="pane-css" role="tabpanel" aria-labelledby="tab-css">
                <label class="form-label" for="f-custom_css"><?= e(t('TEMPLATE_CSS')) ?></label>
                <textarea class="form-control py-code<?= isset($errors['custom_css']) ? ' is-invalid' : '' ?>" id="f-custom_css" name="custom_css" rows="22"
                          spellcheck="false" autocapitalize="off" autocomplete="off" data-code-editor
                          placeholder="/* <?= e(t('TEMPLATE_CSS_PLACEHOLDER')) ?> */&#10;.navbar-brand { letter-spacing: .05em; }"><?= e($css) ?></textarea>
                <?php if (isset($errors['custom_css'])): ?><div class="invalid-feedback"><?= e($errors['custom_css']) ?></div><?php endif ?>
                <div class="form-text"><?= e(t('TEMPLATE_CSS_HINT')) ?></div>
            </div>

            <div class="<?= $pane('code') ?>" id="pane-code" role="tabpanel" aria-labelledby="tab-code">
                <p class="text-body-secondary"><?= e(t('TEMPLATE_CODE_INTRO')) ?></p>
                <?php foreach (['head' => t('TEMPLATE_CODE_HEAD'), 'body' => t('TEMPLATE_CODE_BODY')] as $place => $label): ?>
                    <div class="mb-3">
                        <label class="form-label" for="f-code_<?= $place ?>"><?= e($label) ?></label>
                        <textarea class="form-control py-code<?= isset($errors["code_$place"]) ? ' is-invalid' : '' ?>" id="f-code_<?= $place ?>" name="code_<?= $place ?>" rows="9"
                                  spellcheck="false" autocapitalize="off" autocomplete="off" data-code-editor><?= e($code[$place]) ?></textarea>
                        <?php if (isset($errors["code_$place"])): ?><div class="invalid-feedback"><?= e($errors["code_$place"]) ?></div><?php endif ?>
                    </div>
                <?php endforeach ?>
                <div class="form-text"><?= e(t('TEMPLATE_CODE_HINT')) ?></div>
            </div>

            <div class="<?= $pane('files') ?>" id="pane-files" role="tabpanel" aria-labelledby="tab-files">
                <p><?= e(t('TEMPLATE_FILES_INTRO')) ?></p>
                <ul>
                    <li><?= e(t('TEMPLATE_FILES_VIEW')) ?></li>
                    <li><?= e(t('TEMPLATE_FILES_CSS')) ?></li>
                </ul>
                <?php if (!$files): ?>
                    <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-2" aria-hidden="true"></i><?= e(t('TEMPLATE_FILES_NONE')) ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th scope="col">templates/custom/</th><th scope="col"><?= e(t('TEMPLATE_FILES_ROLE')) ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($files as $f): ?>
                                <tr>
                                    <td><code><?= e($f['path']) ?></code></td>
                                    <td>
                                        <?= e(t($f['replaces'] ? 'TEMPLATE_FILES_REPLACES' : 'TEMPLATE_FILES_OWN')) ?>
                                        <?php if ($f['newer']): ?>
                                            <span class="badge text-bg-warning ms-1" title="<?= e(t('TEMPLATE_FILES_NEWER_HINT')) ?>"><?= e(t('TEMPLATE_FILES_NEWER')) ?></span>
                                        <?php endif ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</form>
