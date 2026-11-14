<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\Controller;
use Pageyard\Auth;
use Pageyard\Blocks;
use Pageyard\Config;
use Pageyard\Lang;
use Pageyard\Pages;
use Pageyard\Request;
use Pageyard\Revisions;
use Pageyard\Session;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/** Earlier versions of pages, HTML and hero blocks and the custom CSS, and items deleted recently. */
final class HistoryController extends Controller
{
    public const TASKS = ['restore'];

    public string $icon = 'bi-clock-history';

    /** view=history&type=page&id=N */
    public function display(): void
    {
        $type = Request::str('type');
        $id = Request::int('id');
        if (!in_array($type, Revisions::TYPES, true)) {
            Session::flash(t('ERR_NOT_FOUND'), 'warning');
            $this->redirect();
        }
        $revisions = Revisions::forItem($type, $id);
        $exists = Revisions::exists($type, $id);
        $this->active = $exists ? self::sidebarKey($type) : 'deleted';
        $this->title = t('HISTORY_OF', $this->itemTitle($type, $id, $revisions));
        $this->link($exists ? self::itemUrl($type, $id) : Uri::admin('view=deleted'), t('HISTORY_BACK'), 'bi-arrow-left', newTab: false);
        $this->render('history/list', [
            'type'      => $type,
            'revisions' => $revisions,
            'current'   => $exists ? $this->currentVersion($type, $id) : null,
            'days'      => Revisions::enabled() ? Revisions::days() : 0,
            'interval'  => (int) Config::get('history_interval', 10),
        ]);
    }

    /** view=revision&id=N: what a version looks like. */
    public function revision(): void
    {
        $revision = Revisions::find(Request::int('id'));
        if ($revision === null) {
            Session::flash(t('HISTORY_GONE'), 'warning');
            $this->redirect('view=deleted');
        }
        $type = $revision['item_type'];
        $id = (int) $revision['item_id'];
        $exists = Revisions::exists($type, $id);
        $this->active = $exists ? self::sidebarKey($type) : 'deleted';
        $this->title = t('HISTORY_VERSION', py_date($revision['version_date']));
        $this->link($exists ? Uri::admin("view=history&type=$type&id=$id") : Uri::admin('view=deleted'), t('HISTORY_BACK'), 'bi-arrow-left', newTab: false);

        // Languages in the configured order, then any that were switched off since.
        $tags = array_keys($revision['content']['translations'] ?? []);
        $tags = [...array_values(array_intersect(Config::languages(), $tags)), ...array_values(array_diff($tags, Config::languages()))];
        $this->render('history/revision', [
            'revision'  => $revision,
            'exists'    => $exists,
            'languages' => Lang::names($tags),
        ]);
    }

    /** view=deleted */
    public function deleted(): void
    {
        $this->active = 'deleted';
        $this->icon = 'bi-trash3';
        $this->title = t('DELETED_TITLE');
        $this->render('history/deleted', ['items' => Revisions::deleted(), 'days' => Revisions::enabled() ? Revisions::days() : 0]);
    }

    public function restore(): void
    {
        $result = Revisions::restore(Request::int('rid'), Auth::id());
        if ($result === null) {
            Session::flash(t('HISTORY_GONE'), 'warning');
            $this->redirect('view=deleted');
        }
        [$revision, $id, $errors, $renamed, $recreated] = $result;
        $type = $revision['item_type'];
        if ($errors) {
            Session::flash(t('HISTORY_RESTORE_FAILED', (string) reset($errors)), 'danger');
            $this->redirect($recreated ? 'view=deleted' : "view=history&type=$type&id=$id");
        }
        Session::flash(t('HISTORY_RESTORED', py_date($revision['version_date'])));
        if ($recreated) {
            Session::flash(t($type === 'page' ? 'HISTORY_RESTORED_PAGE' : 'HISTORY_RESTORED_BLOCK'), 'info');
        }
        if ($renamed) {
            Session::flash(t('ALIAS_CHANGED', strtoupper(implode(', ', $renamed))), 'info');
        }
        Uri::redirect(self::itemUrl($type, $id));
    }

    private static function sidebarKey(string $type): string
    {
        return ['page' => 'pages', 'block' => 'blocks', 'css' => 'template'][$type];
    }

    private static function itemUrl(string $type, int $id): string
    {
        return Uri::admin(['page' => "view=page&id=$id", 'block' => "view=block&id=$id", 'css' => 'view=template'][$type]);
    }

    /** @param list<array> $revisions */
    private function itemTitle(string $type, int $id, array $revisions): string
    {
        return match ($type) {
            'page'  => (string) (Pages::revision($id)['title'] ?? $revisions[0]['title'] ?? "#$id"),
            'block' => (string) (Blocks::find($id)['title'] ?? $revisions[0]['title'] ?? "#$id"),
            default => t('TEMPLATE_TAB_CSS'),
        };
    }

    /** @return array{date: ?string, author: string} */
    private function currentVersion(string $type, int $id): array
    {
        if ($type === 'page') {
            $page = Pages::find($id);
            return ['date' => $page['modified'] ?? null, 'author' => (string) ($page['modified_by_name'] ?? '')];
        }
        if ($type === 'block') {
            return ['date' => Blocks::find($id)['modified'] ?? null, 'author' => ''];
        }
        return ['date' => \Pageyard\Settings::get('custom_css_modified'), 'author' => ''];
    }
}
