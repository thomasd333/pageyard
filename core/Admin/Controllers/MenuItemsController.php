<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\CrudController;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Lang;
use Pageyard\Home;
use Pageyard\Menus;
use Pageyard\Pages;
use Pageyard\Request;
use Pageyard\Session;

defined('PAGEYARD') or exit;

/** Items of one menu (chosen with the "menu" filter, remembered in the session). */
final class MenuItemsController extends CrudController
{
    public const TASKS = ['add', 'edit', 'apply', 'save', 'save2new', 'cancel', 'publish', 'unpublish', 'accessall', 'accessmembers', 'accessguests', 'delete', 'orderup', 'orderdown'];
    protected const LIST_VIEW = 'menuitems';
    protected const ITEM_VIEW = 'menuitem';

    public string $icon = 'bi-list-nested';
    public string $active = 'menus';

    public function display(): void
    {
        $menus = Menus::options();
        if (!$menus) {
            Session::flash(t('MENUS_CREATE_FIRST'), 'info');
            $this->redirect('view=menus');
        }
        $state = $this->listState('menuitems', ['menu' => 0]);
        if (!isset($menus[$state['menu']])) {
            $state['menu'] = (int) array_key_first($menus);
            $_SESSION['list']['menuitems']['menu'] = $state['menu'];
        }
        $lang = (string) Config::get('language');

        $this->title = t('MENUS_ITEMS_TITLE', $menus[$state['menu']]);
        $this->button('menuitems.add', t('TOOLBAR_NEW'), 'bi-plus-lg', 'btn-success');
        $this->button('menuitems.edit', t('TOOLBAR_EDIT'), 'bi-pencil', list: true);
        $this->button('menuitems.publish', t('TOOLBAR_PUBLISH'), 'bi-check-lg', list: true, group: 'actions');
        $this->button('menuitems.unpublish', t('TOOLBAR_UNPUBLISH'), 'bi-x-lg', list: true, group: 'actions');
        $this->accessButtons('menuitems', true);
        $this->button('menuitems.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'text-danger', list: true, group: 'actions');

        $this->render('menuitems/list', [
            'menus'     => $menus,
            'menuId'    => $state['menu'],
            'items'     => Menus::items($state['menu'], $lang),
            'languages' => Config::languages(),
            'hasHome'   => Home::get() !== null,
        ]);
    }

    public function add(): void
    {
        $this->redirect('view=menuitem&menu=' . (int) ($_SESSION['list']['menuitems']['menu'] ?? 0));
    }

    public function save2new(): void
    {
        $this->store();
        $this->redirect('view=menuitem&menu=' . Request::int('menu_id'));
    }

    public function publish(): void
    {
        Session::flash(t('ITEMS_PUBLISHED', Menus::setItemState(Request::ids(), 1)));
        $this->redirect('view=menuitems');
    }

    public function accessall(): void
    {
        $this->changeAccess('#__menu_items', 0, 'menuitems');
    }

    public function accessmembers(): void
    {
        $this->changeAccess('#__menu_items', 1, 'menuitems');
    }

    public function accessguests(): void
    {
        $this->changeAccess('#__menu_items', 2, 'menuitems');
    }

    public function unpublish(): void
    {
        Session::flash(t('ITEMS_UNPUBLISHED', Menus::setItemState(Request::ids(), 0)));
        $this->redirect('view=menuitems');
    }

    public function delete(): void
    {
        Session::flash(t('ITEMS_DELETED', Menus::deleteItems(Request::ids())));
        $this->redirect('view=menuitems');
    }

    public function orderup(): void
    {
        Menus::moveItem(Request::ids()[0] ?? 0, -1);
        $this->redirect('view=menuitems');
    }

    public function orderdown(): void
    {
        Menus::moveItem(Request::ids()[0] ?? 0, 1);
        $this->redirect('view=menuitems');
    }

    protected function form(int $id, ?array $item = null, array $errors = []): void
    {
        if ($item === null) {
            $item = $id ? Menus::findItem($id) : Menus::blankItem(Request::int('menu') ?: (int) ($_SESSION['list']['menuitems']['menu'] ?? 0));
            if ($item === null) {
                $this->notFound();
            }
            $item['labels'] = array_map(fn ($t) => $t['label'], $item['translations']);
        }
        $this->title = t($item['id'] ? 'MENUS_ITEM_EDIT' : 'MENUS_ITEM_NEW');
        $this->formToolbar('menuitems', !$item['id']);
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }

        $lang = (string) Config::get('language');
        $menus = Menus::options();
        $parents = [];
        foreach ($menus as $menuId => $menuTitle) {
            $options = Menus::parentOptions($menuId, $lang, (int) $item['id']);
            if ($options) {
                $parents[$menuTitle] = $options;
            }
        }
        $this->render('menuitems/edit', [
            'item'       => $item,
            'errors'     => $errors,
            'menus'      => $menus,
            'parents'    => $parents,
            'pages'      => Pages::options($lang),
            'categories' => Categories::options($lang),
            'hasHome'    => Home::get() !== null,
            'languages'  => Lang::names(Config::languages()),
        ]);
    }

    protected function store(): int
    {
        $id = Request::int('id');
        $labels = [];
        foreach (Config::languages() as $lang) {
            $labels[$lang] = Request::str("label_$lang");
        }
        $data = [
            'menu_id'     => Request::int('menu_id'),
            'parent_id'   => Request::int('parent_id'),
            'type'        => Request::str('type'),
            'page_id'     => Request::int('page_id'),
            'category_id' => Request::int('category_id'),
            'url'         => Request::str('url'),
            'account'     => Request::str('account'),
            'access'      => Request::int('access'),
            'new_window'  => Request::bool('new_window'),
            'state'       => Request::bool('state') ? 1 : 0,
            'labels'      => $labels,
        ];
        [$newId, $errors] = Menus::saveItem($id, $data);
        if ($errors) {
            $this->form($id, ['id' => $id] + $data, $errors);
            exit;
        }
        $_SESSION['list']['menuitems']['menu'] = $data['menu_id'];
        Session::flash(t('MENUS_ITEM_SAVED'));
        return $newId;
    }
}
