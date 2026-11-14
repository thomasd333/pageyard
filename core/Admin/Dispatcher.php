<?php

namespace Pageyard\Admin;

use Pageyard\Admin\Controllers\BackupsController;
use Pageyard\Admin\Controllers\BlocksController;
use Pageyard\Admin\Controllers\CategoriesController;
use Pageyard\Admin\Controllers\ConfigController;
use Pageyard\Admin\Controllers\DashboardController;
use Pageyard\Admin\Controllers\HistoryController;
use Pageyard\Admin\Controllers\LibraryController;
use Pageyard\Admin\Controllers\LoginController;
use Pageyard\Admin\Controllers\MediaController;
use Pageyard\Admin\Controllers\MenuItemsController;
use Pageyard\Admin\Controllers\MenusController;
use Pageyard\Admin\Controllers\PagesController;
use Pageyard\Admin\Controllers\RedirectsController;
use Pageyard\Admin\Controllers\TemplateController;
use Pageyard\Admin\Controllers\UsersController;
use Pageyard\Auth;
use Pageyard\Backup;
use Pageyard\Config;
use Pageyard\Lang;
use Pageyard\Request;
use Pageyard\Revisions;
use Pageyard\Session;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * Administrator routing, Joomla style:
 *   GET  index.php?view=users           -> UsersController::display()
 *   POST index.php  task=users.save      -> UsersController::save()   (CSRF token required)
 */
final class Dispatcher
{
    /** view => [controller, method] */
    private const VIEWS = [
        'dashboard'  => [DashboardController::class, 'display'],
        'pages'      => [PagesController::class, 'display'],
        'page'       => [PagesController::class, 'edit'],
        'categories' => [CategoriesController::class, 'display'],
        'category'   => [CategoriesController::class, 'edit'],
        'menus'      => [MenusController::class, 'display'],
        'menu'       => [MenusController::class, 'edit'],
        'menuitems'  => [MenuItemsController::class, 'display'],
        'menuitem'   => [MenuItemsController::class, 'edit'],
        'blocks'     => [BlocksController::class, 'display'],
        'block'      => [BlocksController::class, 'edit'],
        'redirects'  => [RedirectsController::class, 'display'],
        'redirect'   => [RedirectsController::class, 'edit'],
        'template'   => [TemplateController::class, 'display'],
        'backups'    => [BackupsController::class, 'display'],
        'media'      => [LibraryController::class, 'display'],
        'history'    => [HistoryController::class, 'display'],
        'revision'   => [HistoryController::class, 'revision'],
        'deleted'    => [HistoryController::class, 'deleted'],
        'users'     => [UsersController::class, 'display'],
        'user'      => [UsersController::class, 'edit'],
        'config'    => [ConfigController::class, 'display'],
    ];

    /** task prefix => controller */
    private const CONTROLLERS = [
        'pages'      => PagesController::class,
        'categories' => CategoriesController::class,
        'media'      => MediaController::class,
        'menus'      => MenusController::class,
        'menuitems'  => MenuItemsController::class,
        'blocks'     => BlocksController::class,
        'redirects'  => RedirectsController::class,
        'template'   => TemplateController::class,
        'backups'    => BackupsController::class,
        'library'    => LibraryController::class,
        'history'    => HistoryController::class,
        'users'  => UsersController::class,
        'config' => ConfigController::class,
        'login'  => LoginController::class,
    ];

    public static function run(): void
    {
        // Cookie for the whole site, so the site can recognise a logged-in administrator (offline mode, previews).
        Session::start(Session::ADMIN, Uri::root());
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');

        // Auth::user() reports an expired session with a translated message, so strings come first.
        Lang::set((string) Config::get('admin_language'));
        $user = Auth::user();
        if (($user['language'] ?? '') !== '') {
            Lang::set($user['language']);
        }

        // Asked by edit forms while someone works in them (admin.js). Auth::user() above has already
        // renewed the session; the token lets a form carry on after logging in again in another tab.
        if (Request::str('view') === 'ping') {
            $user !== null ? self::json(['token' => Session::token()]) : self::jsonError(401, t('LOGIN_EXPIRED'));
        }

        $task = Request::str('task');
        if ($task !== '') {
            self::task($task, $user !== null);
            return;
        }

        // Password reset screens work without a session.
        if (Request::str('view') === 'forgot') {
            (new LoginController())->forgotForm();
            return;
        }
        if (Request::str('view') === 'reset') {
            (new LoginController())->resetForm(Request::str('token'));
            return;
        }

        if ($user === null) {
            (new LoginController())->display();
            return;
        }

        // "Cron" for automatic backups: checked when an administrator opens a screen (content only
        // changes through the back end, so that is when a backup can be due).
        Backup::runIfDue();
        Revisions::purge();

        [$class, $method] = self::VIEWS[Request::str('view', 'dashboard')] ?? self::VIEWS['dashboard'];
        (new $class())->$method();
    }

    private static function task(string $task, bool $loggedIn): void
    {
        [$prefix, $method] = array_pad(explode('.', $task, 2), 2, '');
        $class = self::CONTROLLERS[$prefix] ?? null;

        if (!Request::isPost() || $class === null || !in_array($method, $class::TASKS, true)) {
            Uri::redirect(Uri::admin());
        }
        // AJAX tasks answer errors in JSON instead of redirecting.
        $json = $class === MediaController::class;
        if (!Session::checkToken()) {
            $json ? self::jsonError(403, t('ERR_TOKEN')) : Session::flash(t('ERR_TOKEN'), 'danger');
            Uri::redirect(Uri::admin());
        }
        if (!$loggedIn && $class !== LoginController::class) {
            $json && self::jsonError(401, t('LOGIN_EXPIRED'));
            Uri::redirect(Uri::admin());
        }
        (new $class())->$method();
    }

    private static function jsonError(int $code, string $message): never
    {
        http_response_code($code);
        self::json(['error' => $message]);
    }

    private static function json(array $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
