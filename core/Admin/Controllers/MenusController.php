<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\CrudController;
use Pageyard\Menus;
use Pageyard\Request;
use Pageyard\Session;

defined('PAGEYARD') or exit;

/** Menus (just a name); their items are managed in MenuItemsController. */
final class MenusController extends CrudController
{
    public const TASKS = ['add', 'edit', 'apply', 'save', 'save2new', 'cancel', 'delete'];
    protected const LIST_VIEW = 'menus';
    protected const ITEM_VIEW = 'menu';

    public string $icon = 'bi-menu-button-wide';
    public string $active = 'menus';

    public function display(): void
    {
        $this->title = t('MENUS_TITLE');
        $this->button('menus.add', t('TOOLBAR_NEW'), 'bi-plus-lg', 'btn-success');
        $this->button('menus.edit', t('TOOLBAR_EDIT'), 'bi-pencil', list: true);
        $this->button('menus.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'text-danger', list: true, group: 'actions');
        $this->render('menus/list', ['items' => Menus::list()]);
    }

    public function delete(): void
    {
        [$count, $kept] = Menus::delete(Request::ids());
        if ($count) {
            Session::flash(t('ITEMS_DELETED', $count));
        }
        if ($kept) {
            Session::flash(t('MENUS_ERR_IN_USE', implode(', ', $kept)), 'warning');
        }
        $this->redirect('view=menus');
    }

    protected function form(int $id, ?array $item = null, array $errors = []): void
    {
        $item ??= $id ? Menus::find($id) : ['id' => 0, 'title' => ''];
        if ($item === null) {
            $this->notFound();
        }
        $this->title = t($item['id'] ? 'MENUS_EDIT' : 'MENUS_NEW');
        $this->formToolbar('menus', !$item['id']);
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }
        $this->render('menus/edit', ['item' => $item, 'errors' => $errors]);
    }

    protected function store(): int
    {
        $id = Request::int('id');
        [$newId, $errors] = Menus::save($id, Request::str('title'));
        if ($errors) {
            $this->form($id, ['id' => $id, 'title' => Request::str('title')], $errors);
            exit;
        }
        Session::flash(t('MENUS_SAVED'));
        return $newId;
    }
}
