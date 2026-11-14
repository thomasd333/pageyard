<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Admin\CrudController;
use Pageyard\Categories;
use Pageyard\Blocks;
use Pageyard\Config;
use Pageyard\Lang;
use Pageyard\Menus;
use Pageyard\Pages;
use Pageyard\Request;
use Pageyard\Revisions;
use Pageyard\Session;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

final class BlocksController extends CrudController
{
    public const TASKS = ['add', 'edit', 'apply', 'save', 'save2new', 'cancel', 'publish', 'unpublish', 'accessall', 'accessmembers', 'accessguests', 'delete', 'orderup', 'orderdown'];
    protected const LIST_VIEW = 'blocks';
    protected const ITEM_VIEW = 'block';

    public string $icon = 'bi-grid-1x2';
    public string $active = 'blocks';

    public function display(): void
    {
        $state = $this->listState('blocks', ['search' => '', 'state' => '', 'access' => '', 'position' => '']);
        $this->title = t('BLOCKS_TITLE');
        $this->button('blocks.add', t('TOOLBAR_NEW'), 'bi-plus-lg', 'btn-success');
        $this->button('blocks.edit', t('TOOLBAR_EDIT'), 'bi-pencil', list: true);
        $this->button('blocks.publish', t('TOOLBAR_PUBLISH'), 'bi-check-lg', list: true, group: 'actions');
        $this->button('blocks.unpublish', t('TOOLBAR_UNPUBLISH'), 'bi-x-lg', list: true, group: 'actions');
        $this->accessButtons('blocks', true);
        $this->button('blocks.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'text-danger', list: true, group: 'actions');
        $this->render('blocks/list', ['state' => $state, 'groups' => Blocks::list($state), 'languages' => Config::languages()]);
    }

    public function publish(): void
    {
        Session::flash(t('ITEMS_PUBLISHED', Blocks::setState(Request::ids(), 1)));
        $this->redirect('view=blocks');
    }

    public function accessall(): void
    {
        $this->changeAccess('#__blocks', 0, 'blocks');
    }

    public function accessmembers(): void
    {
        $this->changeAccess('#__blocks', 1, 'blocks');
    }

    public function accessguests(): void
    {
        $this->changeAccess('#__blocks', 2, 'blocks');
    }

    public function unpublish(): void
    {
        Session::flash(t('ITEMS_UNPUBLISHED', Blocks::setState(Request::ids(), 0)));
        $this->redirect('view=blocks');
    }

    public function delete(): void
    {
        $ids = Request::ids();
        $restorable = array_filter($ids, fn (int $id) => Blocks::revision($id) !== null);  // menu blocks aren't kept
        Session::flash(t('ITEMS_DELETED', Blocks::delete($ids)));
        if ($restorable && Revisions::enabled()) {
            Session::flash(t('DELETED_CAN_RESTORE', Revisions::days()), 'info');
        }
        $this->redirect('view=blocks');
    }

    public function orderup(): void
    {
        Blocks::move(Request::ids()[0] ?? 0, -1);
        $this->redirect('view=blocks');
    }

    public function orderdown(): void
    {
        Blocks::move(Request::ids()[0] ?? 0, 1);
        $this->redirect('view=blocks');
    }

    protected function form(int $id, ?array $item = null, array $errors = []): void
    {
        if ($item === null) {
            $item = $id ? Blocks::find($id) : Blocks::blank(in_array(Request::str('position'), Blocks::POSITIONS, true) ? Request::str('position') : 'left');
            if ($item === null) {
                $this->notFound();
            }
        }
        $this->title = t($item['id'] ? 'BLOCKS_EDIT' : 'BLOCKS_NEW');
        $this->formToolbar('blocks', !$item['id']);
        if ($item['id'] && (Revisions::count('block', (int) $item['id']) > 0 || (Revisions::enabled() && $item['type'] !== 'menu'))) {
            $this->link(Uri::admin('view=history&type=block&id=' . (int) $item['id']), t('HISTORY_TOOLBAR', Revisions::count('block', (int) $item['id'])), 'bi-clock-history', newTab: false);
        }
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }
        $lang = (string) Config::get('language');
        $this->render('blocks/edit', [
            'item'       => $item,
            'errors'     => $errors,
            'menus'      => Menus::options(),
            'pages'      => Pages::options($lang),
            'categories' => Categories::options($lang),
            'languages'  => Lang::names(Config::languages()),
        ]);
    }

    protected function store(): int
    {
        $id = Request::int('id');
        $data = [
            'title'        => Request::str('title'),
            'type'         => Request::str('type'),
            'position'     => Request::str('position'),
            'menu_id'      => Request::int('menu_id'),
            'state'        => Request::bool('state') ? 1 : 0,
            'access'       => Request::int('access'),
            'show_title'   => Request::bool('show_title'),
            'assignment'   => Request::str('assignment'),
            'targets'      => array_map('strval', Request::list('targets')),
            'css_class'    => Request::str('css_class'),
            'image'        => Request::str('image'),
            'params'       => [
                'height'        => Request::str('hero_height'),
                'align'         => Request::str('hero_align'),
                'overlay'       => Request::int('hero_overlay'),
                'link_type'     => Request::str('link_type'),
                'link_page'     => Request::int('link_page'),
                'link_category' => Request::int('link_category'),
                'link_url'      => Request::str('link_url'),
                'new_window'    => Request::bool('link_new_window'),
            ],
            'translations' => Request::translations(),
        ];
        [$newId, $errors] = Blocks::save($id, $data);
        if ($errors) {
            $this->form($id, ['id' => $id, 'show_title' => $data['show_title'] ? 1 : 0, 'created' => null, 'modified' => null] + $data, $errors);
            exit;
        }
        Session::flash(t('BLOCKS_SAVED'));
        return $newId;
    }
}
