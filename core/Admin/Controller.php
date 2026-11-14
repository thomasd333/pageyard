<?php

namespace Pageyard\Admin;

use Pageyard\Access;
use Pageyard\Accounts;
use Pageyard\Request;
use Pageyard\Session;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * Base for administrator controllers. Views live in administrator/views/<name>.php and
 * are wrapped in administrator/views/layout.php (sidebar, header, toolbar, messages).
 */
abstract class Controller
{
    /** Methods callable as task=<controller>.<method> (POST with a CSRF token). */
    public const TASKS = [];

    public string $title = '';
    public string $icon = 'bi-speedometer2';
    /** Sidebar item to highlight. */
    public string $active = '';
    /** @var list<array{task: string, label: string, icon: string, class: string, list: bool, novalidate: bool, group: string, url?: string, newTab?: bool}> */
    public array $toolbar = [];
    /** Render without header and sidebar (pages shown inside a dialog, e.g. the media picker). */
    public bool $bare = false;

    protected function button(string $task, string $label, string $icon, string $class = 'btn-outline-secondary',
        bool $list = false, bool $novalidate = false, string $group = ''): void
    {
        $this->toolbar[] = compact('task', 'label', 'icon', 'class', 'list', 'novalidate', 'group');
    }

    /** Toolbar link, by default opening in a new tab (e.g. a preview on the site). */
    protected function link(string $url, string $label, string $icon, string $class = 'btn-outline-secondary', bool $newTab = true): void
    {
        $this->toolbar[] = ['task' => '', 'label' => $label, 'icon' => $icon, 'class' => $class, 'list' => false,
            'novalidate' => false, 'group' => '', 'url' => $url, 'newTab' => $newTab];
    }

    /** Renders a view inside the layout. */
    protected function render(string $view, array $data = []): void
    {
        $controller = $this;
        $content = (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        })(PY_ROOT . "/administrator/views/$view.php", $data + ['controller' => $this]);

        $messages = Session::takeFlash();
        require PY_ROOT . '/administrator/views/' . ($this->bare ? 'layout-bare.php' : 'layout.php');
    }

    /**
     * "Access: ..." entries in the Actions menu of a list, only while accounts on the site are on.
     * Tasks: <prefix>.accessall / accessmembers / accessguests ($guests: menu items and blocks).
     */
    protected function accessButtons(string $prefix, bool $guests = false): void
    {
        if (!Accounts::enabled()) {
            return;
        }
        $this->button("$prefix.accessall", t('ACCESS_SET_ALL'), 'bi-unlock', list: true, group: 'actions');
        $this->button("$prefix.accessmembers", t('ACCESS_SET_MEMBERS'), 'bi-lock', list: true, group: 'actions');
        if ($guests) {
            $this->button("$prefix.accessguests", t('ACCESS_SET_GUESTS'), 'bi-person-dash', list: true, group: 'actions');
        }
    }

    /** Runs a list's access action on the checked rows and goes back to the list. */
    protected function changeAccess(string $table, int $level, string $listView): never
    {
        [$count, $homeKept] = Access::change($table, Request::ids(), $level);
        Session::flash(t('ACCESS_CHANGED', $count));
        if ($homeKept) {
            Session::flash(t('ACCESS_HOME_KEPT'), 'info');
        }
        $this->redirect("view=$listView");
    }

    protected function redirect(string $query = ''): never
    {
        Uri::redirect(Uri::admin($query));
    }

    /**
     * List filters and sorting, remembered in the session per list (as in Joomla).
     * Values in the request override the remembered ones; "reset" restores the defaults.
     *
     * @param array<string, string|int> $defaults
     * @return array<string, string|int>
     */
    protected function listState(string $key, array $defaults): array
    {
        $state = isset($_GET['reset']) ? $defaults : ($_SESSION['list'][$key] ?? $defaults) + $defaults;
        foreach ($defaults as $name => $default) {
            if (isset($_GET[$name]) && is_scalar($_GET[$name])) {
                $state[$name] = is_int($default) ? (int) $_GET[$name] : trim((string) $_GET[$name]);
            }
        }
        // A new search or filter starts again from the first page.
        foreach (array_keys($defaults) as $name) {
            if ($name !== 'page' && isset($_GET[$name]) && !isset($_GET['page'])) {
                $state['page'] = 1;
            }
        }
        $_SESSION['list'][$key] = $state;
        return $state;
    }
}
