<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\Controller;
use Pageyard\Auth;
use Pageyard\Backup;
use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Log;
use Pageyard\Request;
use Pageyard\Session;

defined('PAGEYARD') or exit;

/** System > Backups: list, back up now, download, restore, delete, integrity check, schedule. */
final class BackupsController extends Controller
{
    public const TASKS = ['create', 'download', 'restore', 'delete', 'check', 'settings'];
    public const INTERVALS = [0, 6, 12, 24, 72, 168];

    public string $icon = 'bi-database-check';
    public string $active = 'backups';

    public function display(array $errors = []): void
    {
        $this->title = t('BACKUPS_TITLE');
        $supported = Backup::supported();
        if ($supported) {
            $this->button('backups.create', t('BACKUPS_CREATE'), 'bi-database-add', 'btn-success');
            $this->button('backups.download', t('BACKUPS_DOWNLOAD'), 'bi-download', list: true);
            $this->button('backups.check', t('BACKUPS_CHECK'), 'bi-clipboard2-pulse');
            $this->button('backups.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'text-danger', list: true, group: 'actions');
        }
        $this->render('backups', [
            'supported' => $supported,
            'backups'   => $supported ? Backup::list() : [],
            'status'    => Backup::status(),
            'interval'  => (int) Config::get('backup_interval', 24),
            'keep'      => (int) Config::get('backup_keep', 10),
            'writable'  => is_writable(Config::file()),
            'errors'    => $errors,
        ]);
    }

    public function create(): void
    {
        try {
            $name = Backup::create('manual');
            $name !== null
                ? Session::flash(t('BACKUPS_CREATED', $name))
                : Session::flash(t('BACKUPS_BUSY'), 'warning');
        } catch (\RuntimeException $e) {
            Session::flash(t('BACKUPS_FAILED', $e->getMessage()), 'danger');
        }
        $this->redirect('view=backups');
    }

    public function download(): void
    {
        $name = Request::list('cid')[0] ?? '';
        $path = Backup::path($name);
        if ($path === null) {
            Session::flash(t('ERR_NOT_FOUND'), 'danger');
            $this->redirect('view=backups');
        }
        Log::info("Backup downloaded: $name");
        header_remove('Cache-Control');
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    public function restore(): void
    {
        $name = Request::list('cid')[0] ?? '';
        $me = Auth::user();
        try {
            $result = Backup::restore($name);
        } catch (\Throwable $e) {
            Log::error("Restore of $name failed: " . $e->getMessage());
            Session::flash(t('BACKUPS_RESTORE_FAILED', $e->getMessage()), 'danger');
            $this->redirect('view=backups');
        }
        // The session survives only if the same person exists in the restored data.
        $same = Db::get()->fetchValue('SELECT username FROM #__users WHERE id = ? AND active = 1', [$me['id']]) === $me['username'];
        if ($same) {
            Auth::passwordChanged();
        } else {
            Auth::logout();
        }
        Session::flash(t('BACKUPS_RESTORED', $name));
        if ($result['damaged'] !== null) {
            Session::flash(t('BACKUPS_RESTORED_DAMAGED', $result['damaged']), 'warning');
        }
        $this->redirect('view=backups');
    }

    public function delete(): void
    {
        Session::flash(t('ITEMS_DELETED', Backup::delete(Request::list('cid'))));
        $this->redirect('view=backups');
    }

    public function check(): void
    {
        $problem = Backup::checkLive();
        if ($problem === null) {
            Session::flash(t('BACKUPS_CHECK_OK'));
        } else {
            Log::error("Database integrity check failed: $problem");
            Session::flash(t('BACKUPS_CHECK_FAILED', $problem), 'danger');
        }
        $this->redirect('view=backups');
    }

    public function settings(): void
    {
        $interval = Request::int('backup_interval');
        $keep = Request::int('backup_keep');
        $errors = [];
        if (!in_array($interval, self::INTERVALS, true)) {
            $errors['backup_interval'] = t('ERR_REQUIRED');
        }
        if ($keep < 1 || $keep > 100) {
            $errors['backup_keep'] = t('ERR_RANGE', 1, 100);
        }
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
            $this->display($errors);
            exit;
        }
        try {
            Config::write(array_merge(Config::all(), ['backup_interval' => $interval, 'backup_keep' => $keep]));
            Backup::prune($keep);
            Session::flash(t('BACKUPS_SETTINGS_SAVED'));
        } catch (\RuntimeException $e) {
            Session::flash($e->getMessage(), 'danger');
        }
        $this->redirect('view=backups');
    }
}
