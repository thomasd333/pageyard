<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\Controller;
use Pageyard\Media;
use Pageyard\Request;
use Pageyard\Session;

defined('PAGEYARD') or exit;

/**
 * Content > Media: folders and images in images/ (view=media), and the same screen as a picker inside
 * dialogs (view=media&picker=1): clicking an image sends its path to the opener with postMessage.
 * Uploads go through MediaController (task media.upload, AJAX) with a "dir" field.
 */
final class LibraryController extends Controller
{
    public const TASKS = ['folder', 'delete', 'deletefolder'];

    public string $icon = 'bi-images';
    public string $active = 'media';

    public function display(): void
    {
        $picker = Request::bool('picker');
        $this->bare = $picker;
        $this->title = t('MEDIA_TITLE');
        // Asked for (e.g. the folder of the current image), else the last one used, else where the newest image is.
        $folder = Media::folder(Request::str('folder', (string) ($_SESSION['media_folder'] ?? ''))) ?? Media::newestFolder();
        $_SESSION['media_folder'] = $folder;
        if (!$picker) {
            $this->button('library.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'btn-outline-danger', list: true);
        }
        $files = Media::files($folder);
        $this->render('media', [
            'picker'  => $picker,
            'folder'  => $folder,
            'folders' => Media::folders(),
            'files'   => $files,
            'usage'   => $picker ? [] : Media::usage(array_column($files, 'path')),
            'empty'   => $folder !== 'images' && !$files && count(array_filter(Media::folders(), fn ($f) => str_starts_with($f, "$folder/"))) === 0,
            'maxMb'   => Media::maxSizeMb(),
        ]);
    }

    public function folder(): void
    {
        $parent = Request::str('folder', 'images');
        try {
            $created = Media::createFolder($parent, Request::str('name'));
            $_SESSION['media_folder'] = $created;
            Session::flash(t('MEDIA_FOLDER_CREATED', $created));
        } catch (\RuntimeException $e) {
            Session::flash($e->getMessage(), 'danger');
        }
        $this->back();
    }

    public function delete(): void
    {
        $paths = Request::list('cid');
        $used = array_filter(Media::usage($paths));
        $count = Media::deleteFiles($paths);
        Session::flash(t('ITEMS_DELETED', $count));
        if ($used) {
            Session::flash(t('MEDIA_DELETED_USED', implode(', ', array_map('basename', array_keys($used)))), 'warning');
        }
        $this->back();
    }

    public function deletefolder(): void
    {
        $folder = Request::str('folder');
        if (Media::deleteFolder($folder)) {
            $_SESSION['media_folder'] = dirname($folder);
            Session::flash(t('MEDIA_FOLDER_DELETED', $folder));
        } else {
            Session::flash(t('MEDIA_ERR_FOLDER_NOT_EMPTY'), 'danger');
        }
        $this->back();
    }

    private function back(): never
    {
        $this->redirect('view=media' . (Request::bool('picker') ? '&picker=1' : ''));
    }
}
