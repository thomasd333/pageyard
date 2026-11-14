<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Accounts;
use Pageyard\Admin\CrudController;
use Pageyard\Auth;
use Pageyard\Pagination;
use Pageyard\Request;
use Pageyard\Session;
use Pageyard\Uri;
use Pageyard\Users;

defined('PAGEYARD') or exit;

final class UsersController extends CrudController
{
    public const TASKS = ['add', 'edit', 'apply', 'save', 'save2new', 'cancel', 'activate', 'block', 'delete'];
    protected const LIST_VIEW = 'users';
    protected const ITEM_VIEW = 'user';

    public string $icon = 'bi-people';
    public string $active = 'users';

    public function display(): void
    {
        Accounts::purge();
        $state = $this->listState('users', ['search' => '', 'status' => '', 'kind' => '', 'sort' => 'name', 'dir' => 'asc', 'limit' => 20, 'page' => 1]);
        $limit = in_array($state['limit'], [10, 20, 50, 100, 0], true) ? $state['limit'] : 20;

        $total = Users::list($state, 1, 0)['total'];
        $pagination = new Pagination($total, $limit, $state['page'], fn (int $p) => Uri::admin("view=users&page=$p"));
        $items = Users::list($state, $limit, $pagination->offset())['items'];

        $this->title = t('USERS_TITLE');
        $this->button('users.add', t('TOOLBAR_NEW'), 'bi-plus-lg', 'btn-success');
        $this->button('users.edit', t('TOOLBAR_EDIT'), 'bi-pencil', list: true);
        $this->button('users.activate', t('TOOLBAR_ACTIVATE'), 'bi-check-lg', list: true, group: 'actions');
        $this->button('users.block', t('TOOLBAR_BLOCK'), 'bi-slash-circle', list: true, group: 'actions');
        $this->button('users.delete', t('TOOLBAR_DELETE'), 'bi-trash', 'text-danger', list: true, group: 'actions');

        $this->render('users/list', compact('state', 'items', 'pagination', 'limit'));
    }

    public function activate(): void
    {
        $this->changeActive(true);
    }

    public function block(): void
    {
        $this->changeActive(false);
    }

    public function delete(): void
    {
        [$count, $error] = Users::delete(Request::ids(), Auth::id());
        $error !== '' ? Session::flash($error, 'danger') : Session::flash(t('USERS_DELETED', $count));
        $this->redirect('view=users');
    }

    protected function form(int $id, ?array $user = null, array $errors = []): void
    {
        if ($user === null) {
            // While the site has no accounts, a new user can only be meant for the back end.
            $user = $id ? Users::find($id) : ['id' => 0, 'name' => '', 'username' => '', 'email' => '', 'active' => 1,
                'admin' => Accounts::enabled() ? 0 : 1, 'pending' => '', 'language' => '', 'created' => null, 'last_login' => null];
            if ($user === null) {
                $this->notFound();
            }
        }
        $this->title = t($user['id'] ? 'USERS_EDIT' : 'USERS_NEW');
        $this->icon = 'bi-person';
        $this->formToolbar('users', !$user['id']);
        if ($errors) {
            Session::flash(t('ERR_FORM'), 'danger');
        }
        $this->render('users/edit', ['user' => $user, 'errors' => $errors, 'self' => (int) $user['id'] === Auth::id()]);
    }

    protected function store(): int
    {
        $id = Request::int('id');
        $data = [
            'name'      => Request::str('name'),
            'username'  => Request::str('username'),
            'email'     => Request::str('email'),
            'password'  => Request::raw('password'),
            'password2' => Request::raw('password2'),
            'active'    => Request::bool('active'),
            'admin'     => Request::bool('admin'),
            'language'  => Request::str('language'),
        ];
        [$newId, $errors] = Users::save($id, $data, Auth::id());
        if ($errors) {
            $user = ($id ? Users::find($id) : null) ?? ['id' => 0, 'pending' => '', 'created' => null, 'last_login' => null];
            $this->form($id, array_merge($user, $data, ['active' => $data['active'] ? 1 : 0, 'admin' => $data['admin'] ? 1 : 0]), $errors);
            exit;
        }
        if ($id === Auth::id() && $data['password'] !== '') {
            Auth::passwordChanged();
        }
        Session::flash(t('USERS_SAVED'));
        return $newId;
    }

    private function changeActive(bool $active): void
    {
        [$count, $error] = Users::setActive(Request::ids(), $active, Auth::id());
        $error !== '' ? Session::flash($error, 'danger') : Session::flash(t($active ? 'USERS_ACTIVATED' : 'USERS_BLOCKED', $count));
        $this->redirect('view=users');
    }
}
