<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\CrudController;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Home;
use Pageyard\Lang;
use Pageyard\Pagination;
use Pageyard\Request;
use Pageyard\Session;
use Pageyard\Site\Router;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

final class CategoriesController extends CrudController
{
    public const TASKS = ['add', 'edit', 'apply', 'save', 'save2new', 'cancel', 'publish', 'unpublish', 'accessall', 'accessmembers', 'delete', 'home'];
    protected const LIST_VIEW = 'categories';
    protected const ITEM_VIEW = 'category';

    public string $icon = 'bi-folder';
    public string $active = 'categories';

    public function display(): void
    {
        $state = $this->listState('categories', ['search' => '', 'state' => '', 'access' => '', 'sort' => 'title', 'dir' => 'asc', 'limit' => 20, 'page' => 1]);
        $limit = in_array($state['limit'], [10, 20, 50, 100, 0], true) ? $state['limit'] : 20;
        $lang = (string) Config::get('language');

        $total = Categories::list($state, $lang, 1, 0)['total'];
        $pagination = new Pagination($total, $limit, $state['page'], fn (int $p) => Uri::admin("view=categories&page=$p"));
        $items = Categories::list($state, $lang, $limit, $pagination->offset())['items'];

        $this->title = t('CATEGORIES_TITLE');
        $this->button('categories.add', t('TOOLBAR_NEW'), 'bi-plus-lg', 'btn-success');
        $this->button('categories.edit', t('TOOLBAR_EDIT'), 'bi-pencil', list: true);
        $this->button('categories.home', t('HOME_SET'), 'bi-house', list: true, group: 'actions');
        $this->button('categories.publish', t('TOOLBAR_PUBLISH'), 'bi-check-lg', list: true, group: 'actions');
        $this->button('categories.unpublish', t('TOOLBAR_UNPUBLISH'), 'bi-x-lg', list: true, group: 'actions');
        $this->accessButtons('categories');
        $this->button('categories.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'text-danger', list: true, group: 'actions');

        $this->render('categories/list', compact('state', 'items', 'pagination', 'limit') + [
            'languages' => Config::languages(),
            'home'      => Home::get(),
        ]);
    }

    public function publish(): void
    {
        Session::flash(t('ITEMS_PUBLISHED', Categories::setState(Request::ids(), 1)));
        $this->redirect('view=categories');
    }

    public function accessall(): void
    {
        $this->changeAccess('#__categories', 0, 'categories');
    }

    public function accessmembers(): void
    {
        $this->changeAccess('#__categories', 1, 'categories');
    }

    public function unpublish(): void
    {
        $ids = Request::ids();
        Session::flash(t('ITEMS_UNPUBLISHED', Categories::setState($ids, 0)));
        if (count(Home::without('category', $ids)) < count($ids)) {
            Session::flash(t('HOME_STAYS_PUBLISHED'), 'info');
        }
        $this->redirect('view=categories');
    }

    public function home(): void
    {
        Home::set('category', Request::ids()[0] ?? 0) ? Session::flash(t('HOME_CHANGED')) : Session::flash(t('ERR_NOT_FOUND'), 'danger');
        $this->redirect('view=categories');
    }

    public function delete(): void
    {
        $ids = Request::ids();
        if (count(Home::without('category', $ids)) < count($ids)) {
            Session::flash(t('HOME_NOT_DELETED'), 'warning');
        }
        [$count, $kept] = Categories::delete($ids, (string) Config::get('language'));
        if ($count) {
            Session::flash(t('ITEMS_DELETED', $count));
        }
        if ($kept) {
            Session::flash(t('CATEGORIES_ERR_HAS_PAGES', implode(', ', $kept)), 'warning');
        }
        $this->redirect('view=categories');
    }

    protected function form(int $id, ?array $item = null, array $errors = []): void
    {
        $item ??= $id ? Categories::find($id) : Categories::blank();
        if ($item === null) {
            $this->notFound();
        }
        $this->title = t($item['id'] ? 'CATEGORIES_EDIT' : 'CATEGORIES_NEW');
        $this->formToolbar('categories', !$item['id']);
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }
        $this->render('categories/edit', [
            'item'      => $item,
            'errors'    => $errors,
            'languages' => Lang::names(Config::languages()),
            'isHome'    => $item['id'] && Home::is('category', (int) $item['id']),
            'urls'      => $this->siteUrls((int) $item['id']),
        ]);
    }

    /**
     * Site URL of the saved category per language it has.
     * @return array<string, ?string>
     */
    private function siteUrls(int $id): array
    {
        $urls = [];
        foreach (array_keys($id ? Categories::find($id)['translations'] ?? [] : []) as $lang) {
            $urls[$lang] = Router::categoryUrl($id, $lang);
        }
        return $urls;
    }

    protected function store(): int
    {
        $id = Request::int('id');
        $params = [];
        foreach (Categories::LIST_DEFAULTS as $name => $default) {
            $params[$name] = is_bool($default) ? Request::bool($name) : Request::str($name);
        }
        $data = ['state' => Request::bool('state') ? 1 : 0, 'access' => Request::int('access'), 'params' => $params, 'translations' => Request::translations()];
        [$newId, $errors, $renamed] = Categories::save($id, $data);
        if ($errors) {
            $item = ($id ? Categories::find($id) : null) ?? Categories::blank();
            $this->form($id, ['state' => $data['state'], 'access' => $data['access'], 'params' => $params + Categories::LIST_DEFAULTS, 'translations' => $data['translations']] + $item, $errors);
            exit;
        }
        Session::flash(t('CATEGORIES_SAVED'));
        if ($renamed) {
            Session::flash(t('ALIAS_CHANGED', strtoupper(implode(', ', $renamed))), 'info');
        }
        return $newId;
    }
}
