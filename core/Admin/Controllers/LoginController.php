<?php

namespace Pageyard\Admin\Controllers;

use Pageyard\Auth;
use Pageyard\PasswordReset;
use Pageyard\Request;
use Pageyard\Session;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

final class LoginController
{
    public const TASKS = ['login', 'logout', 'forgot', 'reset'];

    public function display(string $error = '', string $username = ''): void
    {
        $messages = Session::takeFlash();
        if ($error !== '') {
            $messages[] = ['type' => 'danger', 'text' => $error];
        }
        $mode = 'login';
        $resetLink = PasswordReset::available();
        require PY_ROOT . '/administrator/views/login.php';
    }

    /** "Forgot password" form. */
    public function forgotForm(): void
    {
        if (!PasswordReset::available()) {
            Uri::redirect(Uri::admin());
        }
        $messages = Session::takeFlash();
        $mode = 'forgot';
        $username = '';
        require PY_ROOT . '/administrator/views/login.php';
    }

    /** Sends the link; the answer is the same whether the account exists or not. */
    public function forgot(): void
    {
        PasswordReset::request(Request::str('username'));
        Session::flash(t('RESET_REQUESTED', PasswordReset::LIFETIME), 'info');
        Uri::redirect(Uri::admin());
    }

    /** "New password" form opened from the e-mailed link. */
    public function resetForm(string $token, array $errors = []): void
    {
        $user = PasswordReset::userFor($token);
        if ($user === null) {
            Session::flash(t('RESET_INVALID'), 'danger');
            Uri::redirect(Uri::admin());
        }
        $messages = Session::takeFlash();
        foreach ($errors as $error) {
            $messages[] = ['type' => 'danger', 'text' => $error];
        }
        $mode = 'reset';
        $username = $user['username'];
        require PY_ROOT . '/administrator/views/login.php';
    }

    public function reset(): void
    {
        $token = Request::str('reset_token');
        $errors = PasswordReset::complete($token, Request::raw('password'), Request::raw('password2'));
        if (isset($errors['token'])) {
            Session::flash($errors['token'], 'danger');
            Uri::redirect(Uri::admin());
        }
        if ($errors) {
            $this->resetForm($token, $errors);
            return;
        }
        Auth::logout();
        Session::flash(t('RESET_DONE'));
        Uri::redirect(Uri::admin());
    }

    public function login(): void
    {
        $username = Request::str('username');
        $error = Auth::login($username, Request::raw('password'));
        if ($error !== null) {
            http_response_code(401);
            $this->display($error, $username);
            return;
        }
        // Back to the page that asked for the login, if it was inside the administrator.
        $return = Request::str('return');
        Uri::redirect(str_starts_with($return, Uri::admin()) ? $return : Uri::admin());
    }

    public function logout(): void
    {
        Auth::logout();
        Session::flash(t('LOGIN_LOGGED_OUT'), 'info');
        Uri::redirect(Uri::admin());
    }
}
