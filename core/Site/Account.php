<?php

namespace Pageyard\Site;

use Pageyard\Accounts;
use Pageyard\Auth;
use Pageyard\Config;
use Pageyard\PasswordReset;
use Pageyard\Request;
use Pageyard\Session;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * Account pages of the site, under /account (and /<lang>/account), reached only where content gives a
 * 404, like the search:
 *   /account            profile (name, e-mail, password, deleting the account)
 *   /account/login      logging in; /account/logout (POST) logging out
 *   /account/register   registration, when it is open
 *   /account/reset      "forgot password", and the new password form from the e-mailed link
 *   /account/confirm    links from e-mails (new account, new address)
 *
 * The site session (Session::SITE) starts only for visitors who already have one or open these pages,
 * so other visitors get no cookie. Every form carries the session's token. Views: html/account.php.
 */
final class Account
{
    public const PATH = 'account';
    private const ACTIONS = ['login', 'logout', 'register', 'reset', 'confirm'];

    private string $lang;
    private Document $doc;
    /** @var \Closure(string, array): string renders a view */
    private \Closure $view;

    public function __construct(string $lang, Document $doc, \Closure $view)
    {
        $this->lang = $lang;
        $this->doc = $doc;
        $this->view = $view;
    }

    public static function url(string $action, string $lang): string
    {
        return Router::url(self::PATH . ($action !== '' ? "/$action" : ''), $lang);
    }

    /** The action a request path asks for ('' = profile), or null when it isn't an account page. */
    public static function match(string $path, string $lang): ?string
    {
        $prefix = ($lang === (string) Config::get('language') ? '' : "$lang/") . self::PATH;
        $path = trim($path, '/');
        if ($path === $prefix) {
            return '';
        }
        $action = str_starts_with($path, "$prefix/") ? substr($path, strlen($prefix) + 1) : null;
        return in_array($action, self::ACTIONS, true) ? $action : null;
    }

    /**
     * Starts the site session of a visitor who has one ($force: also a new one) and returns who is
     * logged in. Must run before any output. Null while site accounts are off.
     */
    public static function session(bool $force = false): ?array
    {
        if (!Accounts::enabled()) {
            return null;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!$force && !isset($_COOKIE[Session::SITE])) {
                return null;
            }
            Session::start(Session::SITE, Uri::root());
        }
        return self::current();
    }

    /** The logged-in site user, without starting a session. */
    public static function current(): ?array
    {
        if (!Accounts::enabled() || session_status() !== PHP_SESSION_ACTIVE || session_name() !== Session::SITE) {
            return null;
        }
        return Auth::member();
    }

    /**
     * What the "User" block shows, or null while site accounts are off. A guest: login (and register)
     * addresses; a logged-in user: name, profile and the log-out form's address, token and return path.
     * @return ?array{member: bool, name: string, login: string, register: ?string, profile: string, logout: string, token: string, return: string}
     */
    public static function blockData(string $lang): ?array
    {
        if (!Accounts::enabled()) {
            return null;
        }
        // Back to this page afterwards, unless it is an account page itself.
        $here = self::match(Router::requestPath(), $lang) === null
            ? (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) : '';
        $user = self::current();
        return [
            'member'   => $user !== null,
            'name'     => (string) ($user['name'] ?? ''),
            'login'    => self::url('login', $lang) . ($here !== '' ? '?return=' . rawurlencode($here) : ''),
            'register' => $user === null && Accounts::registrationOpen() ? self::url('register', $lang) : null,
            'profile'  => self::url('', $lang),
            'logout'   => self::url('logout', $lang),
            'token'    => $user !== null ? Session::token() : '',
            'return'   => $here,
        ];
    }

    /** The "User" block as plain HTML (side panels, footer; the navbar has its own markup in html/blocks.php). */
    public static function block(string $lang): ?string
    {
        $u = self::blockData($lang);
        if ($u === null) {
            return null;
        }
        if (!$u['member']) {
            $html = '<a class="py-user-login" href="' . e($u['login']) . '">' . e(t('ACCOUNT_LOGIN')) . '</a>';
            if ($u['register'] !== null) {
                $html .= ' <span class="text-body-secondary" aria-hidden="true">·</span> <a class="py-user-register" href="' . e($u['register']) . '">' . e(t('ACCOUNT_REGISTER')) . '</a>';
            }
            return '<div class="py-user">' . $html . '</div>';
        }
        return '<div class="py-user d-flex flex-column align-items-start gap-1">'
            . '<span class="py-user-name">' . e(t('ACCOUNT_HELLO', $u['name'])) . '</span>'
            . '<a class="py-user-profile" href="' . e($u['profile']) . '">' . e(t('ACCOUNT_PROFILE')) . '</a>'
            . self::logoutForm($u, 'btn btn-link p-0 align-baseline py-user-logout') . '</div>';
    }

    /** The log-out button (a POST with the session's token). */
    public static function logoutForm(array $u, string $buttonClass, string $formClass = ''): string
    {
        return '<form class="' . e($formClass) . '" method="post" action="' . e($u['logout']) . '">'
            . '<input type="hidden" name="token" value="' . e($u['token']) . '">'
            . '<input type="hidden" name="return" value="' . e($u['return']) . '">'
            . '<button class="' . e($buttonClass) . '" type="submit">' . e(t('ACCOUNT_LOGOUT')) . '</button></form>';
    }

    /** A return address from the request when it stays on this site (outside the back end), else $default. */
    public static function returnUrl(string $default): string
    {
        $return = Request::str('return');
        $root = Uri::root();
        if ($return === '' || !str_starts_with($return, $root) || str_starts_with($return, '//')
            || preg_match('/[\x00-\x1f\\\\]/', $return) || str_starts_with($return, $root . 'administrator')) {
            return $default;
        }
        return $return;
    }

    /** Content of the page for the action, or null for a 404. May redirect. */
    public function handle(string $action): ?string
    {
        $user = self::session(true);
        $this->doc->noindex = true;
        $this->doc->bodyClass = 'py-view-account';
        header('Cache-Control: no-store');

        if (Request::isPost() && !Session::checkToken()) {
            Session::flash(t('ACCOUNT_ERR_TOKEN'), 'danger');
            Uri::redirect(self::url($action, $this->lang));
        }
        return match ($action) {
            ''         => $this->profile($user),
            'login'    => $this->login($user),
            'logout'   => $this->logout(),
            'register' => $this->register($user),
            'reset'    => $this->reset(),
            'confirm'  => $this->confirm($user),
            default    => null,
        };
    }

    private function login(?array $user): string
    {
        $profile = self::url('', $this->lang);
        if ($user !== null) {
            Uri::redirect(self::returnUrl($profile));
        }
        $errors = [];
        $username = Request::str('username');
        if (Request::isPost()) {
            $error = Auth::login($username, Request::raw('password'), true);
            if ($error === null) {
                Uri::redirect(self::returnUrl($profile));
            }
            $errors[''] = $error;
        }
        return $this->page('login', t('ACCOUNT_LOGIN'), [
            'values'   => ['username' => $username],
            'errors'   => $errors,
            'return'   => self::returnUrl(''),
            'resetUrl' => PasswordReset::available() ? self::url('reset', $this->lang) : null,
            'registerUrl' => Accounts::registrationOpen() ? self::url('register', $this->lang) : null,
        ]);
    }

    private function logout(): never
    {
        if (Request::isPost()) {
            Auth::logout();
        }
        Uri::redirect(Request::isPost() ? self::returnUrl(Router::url('', $this->lang)) : self::url('', $this->lang));
    }

    private function register(?array $user): ?string
    {
        if (!Accounts::registrationOpen()) {
            return null;
        }
        if ($user !== null) {
            Uri::redirect(self::url('', $this->lang));
        }
        $values = ['name' => '', 'username' => '', 'email' => '', 'consent' => false];
        $errors = [];
        if (Request::isPost()) {
            $values = ['name' => Request::str('name'), 'username' => Request::str('username'), 'email' => Request::str('email'),
                'consent' => Request::bool('consent')];
            $done = t(Accounts::activation() === 'admin' ? 'ACCOUNT_REGISTERED_ADMIN' : 'ACCOUNT_REGISTERED_EMAIL');
            // A filled trap: told it worked, nothing created.
            if (Request::str('website') !== '') {
                Session::flash($done, 'info');
                Uri::redirect(self::url('login', $this->lang));
            }
            if (!ContactForm::timeIsValid(Request::str('py_form'), 'register')) {
                $errors[''] = t('CONTACT_ERR_TIME');
            } else {
                $errors = Accounts::register($values + ['password' => Request::raw('password'), 'password2' => Request::raw('password2')], $this->lang);
                if (!$errors) {
                    Session::flash($done, 'info');
                    Uri::redirect(self::url('login', $this->lang));
                }
            }
        }
        return $this->page('register', t('ACCOUNT_REGISTER'), [
            'values'  => $values,
            'errors'  => $errors,
            'stamp'   => ContactForm::stamp(null, 'register'),
            'privacy' => $this->privacyLink(),
            'loginUrl' => self::url('login', $this->lang),
        ]);
    }

    /** "Forgot password" (no token) or the new password form (token from the e-mail). */
    private function reset(): ?string
    {
        if (!PasswordReset::available()) {
            return null;
        }
        $login = self::url('login', $this->lang);
        $token = Request::isPost() ? Request::str('reset_token') : Request::str('token');
        if (Request::isPost() && $token === '') {
            PasswordReset::request(Request::str('username'), $this->lang);
            Session::flash(t('RESET_REQUESTED', PasswordReset::LIFETIME), 'info');
            Uri::redirect($login);
        }
        if ($token === '') {
            return $this->page('forgot', t('RESET_FORGOT_TITLE'), ['values' => [], 'errors' => [], 'loginUrl' => $login]);
        }
        $owner = PasswordReset::userFor($token);
        if ($owner === null) {
            Session::flash(t('RESET_INVALID'), 'danger');
            Uri::redirect(self::url('reset', $this->lang));
        }
        $errors = [];
        if (Request::isPost()) {
            $errors = PasswordReset::complete($token, Request::raw('password'), Request::raw('password2'));
            if (!$errors) {
                Auth::logout();
                Session::flash(t('RESET_DONE'));
                Uri::redirect($login);
            }
        }
        return $this->page('reset', t('RESET_NEW_TITLE'), ['values' => ['username' => $owner['username']], 'errors' => $errors, 'token' => $token]);
    }

    /** Links from e-mails. */
    private function confirm(?array $user): never
    {
        $result = Accounts::confirm(Request::str('token'));
        [$key, $type] = match ($result) {
            'confirmed' => ['ACCOUNT_CONFIRMED', 'success'],
            'approval'  => ['ACCOUNT_CONFIRMED_APPROVAL', 'info'],
            'email'     => ['ACCOUNT_EMAIL_CHANGED', 'success'],
            'taken'     => ['ACCOUNT_EMAIL_TAKEN', 'danger'],
            default     => ['ACCOUNT_LINK_INVALID', 'danger'],
        };
        Session::flash(t($key), $type);
        Uri::redirect(self::url($user !== null ? '' : 'login', $this->lang));
    }

    private function profile(?array $user): string
    {
        $url = self::url('', $this->lang);
        if ($user === null) {
            Uri::redirect(self::url('login', $this->lang) . '?return=' . rawurlencode($url));
        }
        $values = ['name' => $user['name'], 'email' => $user['email']];
        $errors = [];
        if (Request::isPost() && Request::str('task') === 'delete') {
            $error = Accounts::deleteOwn($user, Request::raw('delete_password'));
            if ($error === null) {
                Auth::logout();
                Session::flash(t('ACCOUNT_DELETED'), 'info');
                Uri::redirect(self::url('login', $this->lang));
            }
            Session::flash($error, 'danger');
            Uri::redirect($url . '#py-account-delete');
        }
        if (Request::isPost()) {
            $values = ['name' => Request::str('name'), 'email' => Request::str('email')];
            [$errors, $emailSent] = Accounts::updateProfile($user, $values + ['current' => Request::raw('current'),
                'password' => Request::raw('password'), 'password2' => Request::raw('password2')], $this->lang);
            if (!$errors) {
                if (Request::raw('password') !== '') {
                    Auth::passwordChanged();
                }
                Session::flash(t('ACCOUNT_SAVED'));
                if ($emailSent) {
                    Session::flash(t('ACCOUNT_EMAIL_SENT', Request::str('email'), Accounts::TOKEN_HOURS), 'info');
                }
                Uri::redirect($url);
            }
        }
        return $this->page('profile', t('ACCOUNT_PROFILE'), [
            'user'     => $user,
            'values'   => $values,
            'errors'   => $errors,
            'adminUrl' => $user['admin'] ? Uri::admin() : null,
            'logoutUrl' => self::url('logout', $this->lang),
        ]);
    }

    /** [label, url] of the privacy policy page, or null when there is none in this language. */
    private function privacyLink(): ?array
    {
        $id = Accounts::privacyPage();
        $page = $id > 0 ? Content::pageLink($id, $this->lang) : null;
        $url = $page !== null ? Router::pageUrl((int) $page['id'], $page['category_id'] !== null ? (int) $page['category_id'] : null, $page['alias'], $this->lang) : null;
        return $url === null ? null : [$page['title'], $url];
    }

    private function page(string $mode, string $title, array $vars): string
    {
        $this->doc->title = $title;
        $this->doc->breadcrumbs = [[$title, null]];
        return ($this->view)('account', $vars + [
            'mode'     => $mode,
            'title'    => $title,
            'action'   => self::url(match ($mode) { 'forgot' => 'reset', 'profile' => '', default => $mode }, $this->lang),
            'messages' => Session::takeFlash(),
        ]);
    }
}
