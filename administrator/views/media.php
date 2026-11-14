<?php
/**
 * Content > Media, also used as the picker inside dialogs ($picker).
 * @var bool $picker
 * @var string $folder current folder (images/...)
 * @var list<string> $folders
 * @var list<array{path: string, name: string, size: int, width: int, height: int, modified: int}> $files
 * @var array<string, list<string>> $usage path => where it is used (empty in the picker)
 * @var bool $empty the current folder can be deleted
 * @var int $maxMb
 */

use Pageyard\Admin\Form;
use Pageyard\Images;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$size = static fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', ' ') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
$link = static fn (string $f) => Uri::admin('view=media&folder=' . rawurlencode($f) . ($picker ? '&picker=1' : ''));
$strings = ['uploading' => t('MEDIA_UPLOADING'), 'uploadFailed' => t('MEDIA_ERR_UPLOAD_FAILED'), 'copied' => t('MEDIA_COPIED'),
    'confirmDeleteUsed' => t('MEDIA_CONFIRM_DELETE_USED')];
?>
<div class="row g-4 py-media<?= $picker ? ' py-media-picker' : '' ?>" data-upload-url="<?= e(Uri::admin()) ?>" data-upload-dir="<?= e($folder) ?>">
    <div class="col-md-4 col-xl-3">
        <div class="card py-card">
            <div class="card-header"><?= e(t('MEDIA_FOLDERS')) ?></div>
            <nav class="list-group list-group-flush py-media-folders">
                <?php foreach ($folders as $f): ?>
                    <?php $depth = substr_count($f, '/') ?>
                    <a class="list-group-item list-group-item-action py-1<?= $f === $folder ? ' active' : '' ?>" href="<?= e($link($f)) ?>"
                       style="padding-left: <?= 1 + $depth * .9 ?>rem"<?= $f === $folder ? ' aria-current="true"' : '' ?>>
                        <i class="bi <?= $f === $folder ? 'bi-folder2-open' : 'bi-folder' ?> me-1" aria-hidden="true"></i><?= e($depth ? basename($f) : 'images') ?>
                    </a>
                <?php endforeach ?>
            </nav>
            <div class="card-body border-top">
                <form method="post" action="<?= e(Uri::admin()) ?>" class="input-group input-group-sm">
                    <?= Form::token() ?>
                    <input type="hidden" name="folder" value="<?= e($folder) ?>">
                    <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif ?>
                    <input class="form-control" type="text" name="name" required maxlength="60" placeholder="<?= e(t('MEDIA_NEW_FOLDER')) ?>" aria-label="<?= e(t('MEDIA_NEW_FOLDER')) ?>">
                    <button class="btn btn-outline-secondary" type="submit" name="task" value="library.folder" title="<?= e(t('MEDIA_NEW_FOLDER')) ?>"><i class="bi bi-folder-plus" aria-hidden="true"></i></button>
                </form>
                <?php if ($empty): ?>
                    <form method="post" action="<?= e(Uri::admin()) ?>" class="mt-2">
                        <?= Form::token() ?>
                        <input type="hidden" name="folder" value="<?= e($folder) ?>">
                        <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif ?>
                        <button class="btn btn-sm btn-link text-danger p-0" type="submit" name="task" value="library.deletefolder">
                            <i class="bi bi-trash me-1" aria-hidden="true"></i><?= e(t('MEDIA_DELETE_FOLDER')) ?></button>
                    </form>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-md-8 col-xl-9">
        <label class="py-dropzone mb-3" data-dropzone>
            <input type="file" class="visually-hidden" multiple accept="image/jpeg,image/png,image/gif,image/webp,image/avif" data-upload-input>
            <i class="bi bi-cloud-arrow-up fs-3 d-block" aria-hidden="true"></i>
            <span data-upload-label><?= e(t('MEDIA_DROP', $folder)) ?></span>
            <small class="d-block text-body-secondary"><?= e(t('MEDIA_DROP_HINT', $maxMb)) ?></small>
        </label>

        <form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>">
            <?= Form::token() ?>
            <?php if (!$files): ?>
                <div class="alert alert-info"><i class="bi bi-info-circle me-2" aria-hidden="true"></i><?= e(t('MEDIA_EMPTY')) ?></div>
            <?php else: ?>
                <?php if ($picker): ?><p class="small text-body-secondary"><?= e(t('MEDIA_PICK_HINT')) ?></p><?php endif ?>
                <div class="row row-cols-2 row-cols-sm-3 row-cols-xl-4 g-3">
                    <?php foreach ($files as $i => $f): ?>
                        <?php $used = $usage[$f['path']] ?? [] ?>
                        <div class="col">
                            <div class="card h-100 py-media-item"<?= $used ? ' data-used="' . e($f['name']) . '"' : '' ?>>
                                <?php if ($picker): ?>
                                    <button type="button" class="py-media-thumb" data-pick="<?= e($f['path']) ?>" title="<?= e($f['name']) ?>">
                                        <img src="<?= e(Uri::root() . Images::thumb($f['path'], 320)) ?>" alt="" loading="lazy">
                                    </button>
                                <?php else: ?>
                                    <label class="py-media-thumb" for="cb<?= $i ?>">
                                        <img src="<?= e(Uri::root() . Images::thumb($f['path'], 320)) ?>" alt="" loading="lazy">
                                    </label>
                                <?php endif ?>
                                <div class="card-body p-2 small">
                                    <div class="d-flex align-items-start gap-2">
                                        <?php if (!$picker): ?>
                                            <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" name="cid[]" value="<?= e($f['path']) ?>" id="cb<?= $i ?>" aria-label="<?= e($f['name']) ?>">
                                        <?php endif ?>
                                        <div class="text-truncate" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></div>
                                    </div>
                                    <div class="text-body-secondary"><?= $f['width'] ? (int) $f['width'] . '&times;' . (int) $f['height'] . ' &middot; ' : '' ?><?= e($size($f['size'])) ?></div>
                                    <?php if (!$picker): ?>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <?php if ($used): ?>
                                                <span class="badge text-bg-success" title="<?= e(implode("\n", $used)) ?>"><?= e(t('MEDIA_USED', count($used))) ?></span>
                                            <?php else: ?>
                                                <span class="badge text-bg-light border"><?= e(t('MEDIA_UNUSED')) ?></span>
                                            <?php endif ?>
                                            <span>
                                                <a class="btn btn-sm btn-link p-0 me-1" href="<?= e(Uri::root() . $f['path']) ?>" target="_blank" rel="noopener" title="<?= e(t('MEDIA_OPEN')) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                                                <button type="button" class="btn btn-sm btn-link p-0" data-copy="<?= e($f['path']) ?>" title="<?= e(t('MEDIA_COPY_PATH')) ?>"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
                                            </span>
                                        </div>
                                    <?php endif ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </form>
    </div>
</div>
<script>window.PY_MEDIA = <?= json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(Uri::media('admin/media.js')) ?>?v=<?= e(PY_VERSION) ?>" defer></script>
