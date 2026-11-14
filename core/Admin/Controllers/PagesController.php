<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\CrudController;
use Pageyard\Auth;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Home;
use Pageyard\Lang;
use Pageyard\Media;
use Pageyard\Pages;
use Pageyard\Pagination;
use Pageyard\Request;
use Pageyard\Revisions;
use Pageyard\Site\Router;
use Pageyard\Session;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

final class PagesController extends CrudController
{
    public const TASKS = ['add', 'edit', 'apply', 'save', 'save2new', 'save2copy', 'cancel', 'publish', 'unpublish', 'accessall', 'accessmembers', 'delete', 'home', 'move', 'orderup', 'orderdown'];
    protected const LIST_VIEW = 'pages';
    protected const ITEM_VIEW = 'page';

    public string $icon = 'bi-file-earmark-text';
    public string $active = 'pages';

    public function display(): void
    {
        $state = $this->listState('pages', ['search' => '', 'state' => '', 'access' => '', 'category' => '', 'sort' => 'publish_up',
            'dir' => 'desc', 'limit' => 20, 'page' => 1]);
        $limit = in_array($state['limit'], [10, 20, 50, 100, 0], true) ? $state['limit'] : 20;
        $lang = (string) Config::get('language');

        $total = Pages::list($state, $lang, 1, 0)['total'];
        $pagination = new Pagination($total, $limit, $state['page'], fn (int $p) => Uri::admin("view=pages&page=$p"));
        $items = Pages::list($state, $lang, $limit, $pagination->offset())['items'];

        $this->title = t('PAGES_TITLE');
        $this->button('pages.add', t('TOOLBAR_NEW'), 'bi-plus-lg', 'btn-success');
        $this->button('pages.edit', t('TOOLBAR_EDIT'), 'bi-pencil', list: true);
        $this->button('pages.home', t('HOME_SET'), 'bi-house', list: true, group: 'actions');
        $this->button('pages.publish', t('TOOLBAR_PUBLISH'), 'bi-check-lg', list: true, group: 'actions');
        $this->button('pages.unpublish', t('TOOLBAR_UNPUBLISH'), 'bi-x-lg', list: true, group: 'actions');
        $this->accessButtons('pages');
        $this->button('pages.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'text-danger', list: true, group: 'actions');

        $this->render('pages/list', compact('state', 'items', 'pagination', 'limit') + [
            'categories' => Categories::options($lang),
            'languages'  => Config::languages(),
            'home'       => Home::get(),
        ]);
    }

    /** Moves the checked pages to the category chosen under the list. */
    public function move(): void
    {
        $target = Request::str('move_category');
        $category = ctype_digit($target) ? (int) $target : null;
        if ($target === '' || ($category !== null && !isset(Categories::options((string) Config::get('language'))[$category]))) {
            Session::flash(t('PAGES_MOVE_CHOOSE'), 'warning');
            $this->redirect('view=pages');
        }
        Session::flash(t('PAGES_MOVED', Pages::moveTo(Request::ids(), $category, Auth::id())));
        $this->redirect('view=pages');
    }

    public function orderup(): void
    {
        Pages::moveInOrder(Request::ids()[0] ?? 0, -1);
        $this->redirect('view=pages');
    }

    public function orderdown(): void
    {
        Pages::moveInOrder(Request::ids()[0] ?? 0, 1);
        $this->redirect('view=pages');
    }

    public function publish(): void
    {
        Session::flash(t('ITEMS_PUBLISHED', Pages::setState(Request::ids(), 1, Auth::id())));
        $this->redirect('view=pages');
    }

    public function accessall(): void
    {
        $this->changeAccess('#__pages', 0, 'pages');
    }

    public function accessmembers(): void
    {
        $this->changeAccess('#__pages', 1, 'pages');
    }

    public function unpublish(): void
    {
        $ids = Request::ids();
        Session::flash(t('ITEMS_UNPUBLISHED', Pages::setState($ids, 0, Auth::id())));
        if (count(Home::without('page', $ids)) < count($ids)) {
            Session::flash(t('HOME_STAYS_PUBLISHED'), 'info');
        }
        $this->redirect('view=pages');
    }

    public function delete(): void
    {
        $ids = Request::ids();
        $count = Pages::delete($ids);
        Session::flash(t('ITEMS_DELETED', $count));
        if ($count && Revisions::enabled()) {
            Session::flash(t('DELETED_CAN_RESTORE', Revisions::days()), 'info');
        }
        if (count(Home::without('page', $ids)) < count($ids)) {
            Session::flash(t('HOME_NOT_DELETED'), 'warning');
        }
        $this->redirect('view=pages');
    }

    public function home(): void
    {
        Home::set('page', Request::ids()[0] ?? 0) ? Session::flash(t('HOME_CHANGED')) : Session::flash(t('ERR_NOT_FOUND'), 'danger');
        $this->redirect('view=pages');
    }

    protected function form(int $id, ?array $item = null, array $errors = []): void
    {
        if ($item === null) {
            $item = $id ? Pages::find($id) : Pages::blank();
            if ($item === null) {
                $this->notFound();
            }
            $item['publish_up_local'] = py_date($item['publish_up'], 'Y-m-d\TH:i');
        }
        $this->title = t($item['id'] ? 'PAGES_EDIT' : 'PAGES_NEW');
        $this->formToolbar('pages', !$item['id'], copy: true);
        if ($item['id']) {
            $this->link(self::previewUrl((int) $item['id'], (string) Config::get('language')), t('TOOLBAR_PREVIEW'), 'bi-eye');
            if (Revisions::linked('page', (int) $item['id'])) {
                $this->link(Uri::admin('view=history&type=page&id=' . (int) $item['id']), t('HISTORY_TOOLBAR', Revisions::count('page', (int) $item['id'])), 'bi-clock-history', newTab: false);
            }
        }
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }
        $this->render('pages/edit', [
            'item'       => $item,
            'errors'     => $errors,
            'languages'  => Lang::names(Config::languages()),
            'categories' => Categories::options((string) Config::get('language')),
            'maxUpload'  => Media::maxSizeMb(),
            'urls'       => $this->siteUrls($item),
        ]);
    }

    /**
     * "Save as Copy": the form as a new, unpublished page; titles get " (copy)" and aliases are
     * generated again from them (unique). Opens the copy.
     */
    public function save2copy(): void
    {
        $_POST['id'] = '0';
        unset($_POST['state']);
        foreach ($_POST['tr'] ?? [] as $lang => $fields) {
            if (is_array($fields) && trim((string) ($fields['title'] ?? '')) !== '') {
                $_POST['tr'][$lang]['title'] = trim((string) $fields['title']) . ' ' . t('PAGES_COPY_SUFFIX');
                $_POST['tr'][$lang]['alias'] = '';
            }
        }
        $id = $this->store();
        Session::flash(t('PAGES_COPIED'), 'info');
        $this->redirect("view=page&id=$id");
    }

    /** Site address that shows a page to administrators whatever its state. */
    public static function previewUrl(int $id, string $lang): string
    {
        return Uri::root() . '?' . http_build_query(['preview' => $id, 'lang' => $lang]);
    }

    /**
     * Where the saved page can be seen on the site, per language (null = its category has no
     * translation in that language).
     * @return array<string, ?string>
     */
    private function siteUrls(array $item): array
    {
        $urls = [];
        if (!$item['id']) {
            return $urls;
        }
        $saved = Pages::find((int) $item['id']);
        foreach ($saved['translations'] ?? [] as $lang => $tr) {
            $category = $saved['category_id'] !== null ? (int) $saved['category_id'] : null;
            $urls[$lang] = Router::pageUrl((int) $item['id'], $category, $tr['alias'], $lang);
        }
        return $urls;
    }

    protected function store(): int
    {
        $id = Request::int('id');
        $translations = Request::translations();
        foreach ($translations as $lang => $fields) {
            [$translations[$lang]['intro'], $translations[$lang]['body']] = Pages::splitText($fields['text'] ?? '');
            unset($translations[$lang]['text']);
        }
        $data = [
            'state'        => Request::bool('state') ? 1 : 0,
            'access'       => Request::int('access'),
            'category_id'  => Request::int('category_id'),
            'image'        => Request::str('image'),
            'publish_up'   => Request::str('publish_up'),
            'translations' => $translations,
        ];
        [$newId, $errors, $renamed] = Pages::save($id, $data, Auth::id());
        if ($errors) {
            $item = ($id ? Pages::find($id) : null) ?? Pages::blank();
            $this->form($id, array_merge($item, $data, ['publish_up_local' => $data['publish_up']]), $errors);
            exit;
        }
        Session::flash(t('PAGES_SAVED'));
        if ($renamed) {
            Session::flash(t('ALIAS_CHANGED', strtoupper(implode(', ', $renamed))), 'info');
        }
        return $newId;
    }
}
