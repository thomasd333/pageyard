<?php
/**
 * Loads TinyMCE for textareas with class "py-editor" and the image field helper (media/admin/editor.js).
 * Include once in a view that has editors or an image field.
 */

use Pageyard\Lang;
use Pageyard\Media;
use Pageyard\Session;
use Pageyard\Site\Scripts;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$editorConfig = [
    'base'      => Uri::media('vendor/tinymce'),
    'siteRoot'  => Uri::root(),
    'language'  => is_file(PY_ROOT . '/media/vendor/tinymce/langs/' . Lang::current() . '.js') ? Lang::current() : '',
    'uploadUrl' => Uri::admin(),
    'token'     => Session::token(),
    // The file's time, not PY_VERSION: TinyMCE loads this CSS after the page, so Ctrl+F5 doesn't refresh a cached copy.
    'css'       => [
        Uri::media('vendor/bootstrap/css/bootstrap.min.css'),
        Uri::media('admin/editor-content.css') . '?v=' . filemtime(PY_ROOT . '/media/admin/editor-content.css'),
    ],
    'scripts'   => Scripts::available(),
    'galleries' => Media::galleryFolders(),
    'picker'    => Uri::admin('view=media&picker=1'),
    // TinyMCE texts missing from its language packs.
    'tinymceStrings' => [
        'Alignment {0}'   => t('EDITOR_TB_ALIGNMENT'),
        'Block {0}'       => t('EDITOR_TB_BLOCK'),
        'Text color {0}'  => t('EDITOR_TB_COLOR'),
        'Text color menu' => t('EDITOR_TB_COLOR_MENU'),
    ],
    'strings'   => [
        'uploadFailed' => t('MEDIA_ERR_UPLOAD_FAILED'),
        'script'       => t('EDITOR_SCRIPT'),
        'scriptFile'   => t('EDITOR_SCRIPT_FILE'),
        'scriptParams' => t('EDITOR_SCRIPT_PARAMS'),
        'scriptHint'   => t('EDITOR_SCRIPT_HINT'),
        'scriptNone'   => t('EDITOR_SCRIPT_NONE'),
        'readMore'     => t('SITE_READ_MORE'),
        'readMoreHint' => t('EDITOR_READ_MORE_HINT'),
        'gallery'         => t('EDITOR_GALLERY'),
        'galleryFolder'   => t('EDITOR_GALLERY_FOLDER'),
        'galleryColumns'  => t('EDITOR_GALLERY_COLUMNS'),
        'galleryOrder'    => t('EDITOR_GALLERY_ORDER'),
        'galleryByName'   => t('EDITOR_GALLERY_BY_NAME'),
        'galleryNewest'   => t('EDITOR_GALLERY_NEWEST'),
        'galleryLimit'    => t('EDITOR_GALLERY_LIMIT'),
        'galleryCaptions' => t('EDITOR_GALLERY_CAPTIONS'),
        'galleryHint'     => t('EDITOR_GALLERY_HINT'),
        'galleryNone'     => t('EDITOR_GALLERY_NONE'),
        'library'      => t('MEDIA_FROM_LIBRARY'),
        'libraryTitle' => t('MEDIA_PICK_TITLE'),
        'close'        => t('CLOSE'),
    ],
];
?>
<script>window.PY_EDITOR = <?= json_encode($editorConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(Uri::media('vendor/tinymce/tinymce.min.js')) ?>" defer></script>
<script src="<?= e(Uri::media('admin/editor.js')) ?>?v=<?= e(PY_VERSION) ?>" defer></script>
