<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\CrudController;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Pages;
use Pageyard\Pagination;
use Pageyard\Redirects;
use Pageyard\Request;
use Pageyard\Session;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/** System > Redirects: the ones recorded when items were renamed, and others added by hand. */
final class RedirectsController extends CrudController
{
    public const TASKS = ['add', 'edit', 'apply', 'save', 'save2new', 'cancel', 'delete'];
    protected const LIST_VIEW = 'redirects';
    protected const ITEM_VIEW = 'redirect';

    public string $icon = 'bi-signpost-split';
    public string $active = 'redirects';

    public function display(): void
    {
        $state = $this->listState('redirects', ['search' => '', 'kind' => '', 'sort' => 'created', 'dir' => 'desc', 'limit' => 20, 'page' => 1]);
        $limit = in_array($state['limit'], [10, 20, 50, 100, 0], true) ? $state['limit'] : 20;
        $total = Redirects::list($state, 1, 0)['total'];
        $pagination = new Pagination($total, $limit, $state['page'], fn (int $p) => Uri::admin("view=redirects&page=$p"));
        $items = Redirects::list($state, $limit, $pagination->offset())['items'];

        $this->title = t('REDIRECTS_TITLE');
        $this->button('redirects.add', t('TOOLBAR_NEW'), 'bi-plus-lg', 'btn-success');
        $this->button('redirects.edit', t('TOOLBAR_EDIT'), 'bi-pencil', list: true);
        $this->button('redirects.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'text-danger', list: true, group: 'actions');
        $this->render('redirects/list', compact('state', 'items', 'pagination', 'limit'));
    }

    public function delete(): void
    {
        Session::flash(t('ITEMS_DELETED', Redirects::delete(Request::ids())));
        $this->redirect('view=redirects');
    }

    protected function form(int $id, ?array $item = null, array $errors = []): void
    {
        if ($item === null) {
            $item = $id ? Redirects::get($id) : Redirects::blank();
            if ($item === null) {
                $this->notFound();
            }
            if ($id) {
                $item['from'] = '/' . ($item['lang'] !== Config::get('language') ? $item['lang'] . '/' : '') . $item['path'];
            }
        }
        $this->title = t($item['id'] ? 'REDIRECTS_EDIT' : 'REDIRECTS_NEW');
        $this->formToolbar('redirects', !$item['id']);
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }
        $lang = (string) Config::get('language');
        $this->render('redirects/edit', ['item' => $item, 'errors' => $errors,
            'pages' => Pages::options($lang), 'categories' => Categories::options($lang)]);
    }

    protected function store(): int
    {
        $id = Request::int('id');
        $type = Request::str('target_type');
        $data = ['from' => Request::str('from'), 'target_type' => $type, 'url' => Request::str('url'),
            'target_id' => $type === 'category' ? Request::int('category_id') : Request::int('page_id')];
        [$newId, $errors] = Redirects::save($id, $data);
        if ($errors) {
            $this->form($id, ['id' => $id] + $data, $errors);
            exit;
        }
        Session::flash(t('REDIRECTS_SAVED'));
        return $newId;
    }
}
