<?php

use Pageyard\Accounts;
use Pageyard\Aliases;
use Pageyard\Auth;
use Pageyard\Database\Db;
use Pageyard\PasswordReset;
use Pageyard\Site\Account;
use Pageyard\Users;

defined('PAGEYARD') or exit;

/** mail_fixture() with accounts and registration switched on. */
function accounts_fixture(array $config = []): ArrayObject
{
    $sent = mail_fixture($config + ['site_accounts' => true, 'registration' => true, 'registration_activation' => 'email']);
    $_SESSION = [];
    Auth::logout();
    return $sent;
}

/** A registered (not administrator) user. */
function site_user(string $username = 'reg'): int
{
    [$id, $errors] = Users::save(0, ['name' => 'Reg', 'username' => $username, 'email' => "$username@example.com", 'password' => 'regpassword',
        'password2' => 'regpassword', 'active' => true, 'admin' => false, 'language' => ''], 0);
    same([], $errors);
    return $id;
}

function registration(array $overrides = []): array
{
    return $overrides + ['name' => 'Jan Nowak', 'username' => 'jan', 'email' => 'Jan@Example.org', 'password' => 'janpassword',
        'password2' => 'janpassword', 'consent' => false];
}

/** The token from a link to the given account page in an e-mail. */
function account_token(array $mail, string $action): string
{
    check((bool) preg_match('~https://example\.com/cms/account/' . $action . '\?token=([0-9a-f]{64})~', $mail['text'], $m), 'link in: ' . $mail['text']);
    return $m[1];
}

return [
    'accounts: administrators and site users each log in to their own side' => function () {
        accounts_fixture();
        $admin = mail_user();
        $reg = site_user();

        same(t('LOGIN_NOT_ADMIN'), Auth::login('reg', 'regpassword'));
        same(t('LOGIN_FAILED'), Auth::login('reg', 'wrong'), 'the reason only comes with the right password');
        same(null, Auth::login('reg', 'regpassword', true));
        same($reg, (int) Auth::member()['id']);
        same(null, Auth::user(), 'a site session never opens the back end');
        same(0, Auth::id());

        Auth::logout();
        same(null, Auth::login('anna', 'oldpassword', true), 'administrators may log in on the site too');
        same($admin, (int) Auth::member()['id']);
        same(null, Auth::user());

        Auth::logout();
        same(null, Auth::login('anna', 'oldpassword'));
        same($admin, Auth::id());
        same(null, Auth::member(), 'and a back-end session is not a site one');

        // Losing the flag ends the back-end session at the next request.
        Db::get()->update('#__users', ['admin' => 0], ['id' => $admin]);
        (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
        same(null, Auth::user());
    },

    'accounts: the site session has its own lifetime' => function () {
        accounts_fixture(['session_lifetime' => 10, 'site_session_lifetime' => 600]);
        site_user();
        Auth::login('reg', 'regpassword', true);
        $_SESSION['last_seen'] = time() - 3600;
        (new ReflectionProperty(Auth::class, 'member'))->setValue(null, null);
        check(Auth::member() !== null, 'an hour is within 600 minutes');
        $_SESSION['last_seen'] = time() - 601 * 60;
        (new ReflectionProperty(Auth::class, 'member'))->setValue(null, null);
        same(null, Auth::member());
    },

    'registration: confirmed by e-mail, then logging in' => function () {
        $sent = accounts_fixture();
        mail_user();
        check(Accounts::registrationOpen());

        $errors = Accounts::register(registration(['username' => 'a b', 'password' => 'short', 'email' => 'x']), 'en');
        same(['username', 'email', 'password'], array_keys($errors));
        same(0, count($sent), 'nothing sent for a form with errors');

        same([], Accounts::register(registration(), 'en'));
        $user = Users::find(3) ?? Db::get()->fetchRow("SELECT * FROM #__users WHERE username = 'jan'");
        same('email', $user['pending']);
        same(0, (int) $user['admin']);
        same('jan@example.org', $user['email']);
        same(1, count($sent));
        same('jan@example.org', $sent[0]['to']);
        $token = account_token($sent[0], 'confirm');

        same(t('LOGIN_PENDING_EMAIL'), Auth::login('jan', 'janpassword', true));
        same(t('LOGIN_FAILED'), Auth::login('jan', 'wrongpassword', true));
        same(null, Accounts::confirm('x'));
        same('confirmed', Accounts::confirm($token));
        same(null, Accounts::confirm($token), 'a link works once');
        same(null, Auth::login('jan', 'janpassword', true));
        same(1, count($sent), 'administrators are not told unless asked to');
    },

    'registration: a taken address or username is said' => function () {
        $sent = accounts_fixture();
        mail_user();
        same(['email' => t('ACCOUNT_ERR_EMAIL_TAKEN')], Accounts::register(registration(['email' => 'ANNA@example.com']), 'en'));
        same(1, (int) Db::get()->fetchValue('SELECT COUNT(*) FROM #__users'), 'no account made');
        same(0, count($sent));
        same(['username'], array_keys(Accounts::register(registration(['username' => 'anna', 'email' => 'other@example.org']), 'en')));
    },

    'registration: consent, closed registration, limits' => function () {
        accounts_fixture(['privacy_page' => 5]);
        mail_user();
        same(['consent'], array_keys(Accounts::register(registration(), 'en')));
        same([], Accounts::register(registration(['consent' => true]), 'en'));

        accounts_fixture(['registration' => false]);
        check(!Accounts::registrationOpen());
        accounts_fixture(['mail_transport' => 'off']);
        check(!Accounts::registrationOpen(), 'needs e-mail');
        accounts_fixture(['site_accounts' => false]);
        check(!Accounts::registrationOpen(), 'needs accounts on the site');

        accounts_fixture();
        for ($i = 1; $i <= Accounts::REGISTER_PER_HOUR; $i++) {
            same([], Accounts::register(registration(['username' => "u$i", 'email' => "u$i@example.org"]), 'en'));
        }
        same([''], array_keys(Accounts::register(registration(['username' => 'u9', 'email' => 'u9@example.org']), 'en')));
    },

    'registration: approval by an administrator, alone or after the e-mail' => function () {
        $sent = accounts_fixture(['registration_activation' => 'admin']);
        $admin = mail_user();
        same([], Accounts::register(registration(), 'en'));
        $id = (int) Db::get()->fetchValue("SELECT id FROM #__users WHERE username = 'jan'");
        same('admin', Users::find($id)['pending']);
        same(1, count($sent));
        same('anna@example.com', $sent[0]['to'], 'the administrator is asked');
        check(str_contains($sent[0]['text'], "https://example.com/cms/administrator/index.php?view=user&id=$id"));
        same(t('LOGIN_PENDING_ADMIN'), Auth::login('jan', 'janpassword', true));

        same([1, ''], Users::setActive([$id], true, $admin));
        same('', Users::find($id)['pending']);
        same('jan@example.org', $sent[1]['to'], 'the owner is told');
        check(str_contains($sent[1]['text'], 'https://example.com/cms/account/login'));
        same(null, Auth::login('jan', 'janpassword', true));

        $sent = accounts_fixture(['registration_activation' => 'both']);
        mail_user();
        Accounts::register(registration(), 'en');
        same('jan@example.org', $sent[0]['to']);
        same('approval', Accounts::confirm(account_token($sent[0], 'confirm')));
        same('anna@example.com', $sent[1]['to']);
        same(t('LOGIN_PENDING_ADMIN'), Auth::login('jan', 'janpassword', true));
    },

    'registration: administrators can be told about confirmed accounts' => function () {
        $sent = accounts_fixture(['registration_notify' => true]);
        mail_user();
        Accounts::register(registration(), 'en');
        same('confirmed', Accounts::confirm(account_token($sent[0], 'confirm')));
        same('anna@example.com', $sent[1]['to']);
        same(t('ACCOUNT_MAIL_NEW_SUBJECT', 'Site'), $sent[1]['subject']);
    },

    'registration: unconfirmed accounts go after 48 hours' => function () {
        $sent = accounts_fixture();
        mail_user();
        Accounts::register(registration(), 'en');
        $token = account_token($sent[0], 'confirm');
        $old = gmdate('Y-m-d H:i:s', time() - Accounts::TOKEN_HOURS * 3600 - 60);
        Db::get()->query('UPDATE #__users SET created = ? WHERE username = ?', [$old, 'jan']);
        Db::get()->query('UPDATE #__user_tokens SET created = ?', [$old]);
        same(1, Accounts::purge());
        same(null, Db::get()->fetchRow("SELECT * FROM #__users WHERE username = 'jan'"));
        same(null, Accounts::confirm($token));
        same([], Accounts::register(registration(), 'en'), 'the name and the address are free again');
    },

    'profile: name, password, a new address after confirming it, deleting the account' => function () {
        $sent = accounts_fixture();
        $admin = mail_user();
        $id = site_user();
        $user = Users::find($id);
        $data = ['name' => 'Reg Renamed', 'email' => 'reg@example.com', 'current' => '', 'password' => '', 'password2' => ''];

        same([[], false], Accounts::updateProfile($user, $data, 'en'), 'the name needs no password');
        same('Reg Renamed', Users::find($id)['name']);

        [$errors] = Accounts::updateProfile($user, ['password' => 'newpassword', 'password2' => 'newpassword'] + $data, 'en');
        same(['current'], array_keys($errors));
        [$errors] = Accounts::updateProfile($user, ['password' => 'newpassword', 'password2' => 'other', 'current' => 'regpassword'] + $data, 'en');
        same(['password2'], array_keys($errors));
        same([[], false], Accounts::updateProfile($user, ['password' => 'newpassword', 'password2' => 'newpassword', 'current' => 'regpassword'] + $data, 'en'));
        $user = Users::find($id);
        check(password_verify('newpassword', $user['password']));

        same([[], true], Accounts::updateProfile($user, ['email' => 'New@Example.org', 'current' => 'newpassword'] + $data, 'en'));
        same('reg@example.com', Users::find($id)['email'], 'not before the link');
        same('new@example.org', $sent[0]['to']);
        same('email', Accounts::confirm(account_token($sent[0], 'confirm')));
        same('new@example.org', Users::find($id)['email']);

        // An address taken in the meantime stays with its account.
        $user = Users::find($id);
        Accounts::updateProfile($user, ['email' => 'later@example.com', 'current' => 'newpassword'] + $data, 'en');
        $token = account_token($sent[1], 'confirm');
        site_user('later');
        same('taken', Accounts::confirm($token));
        same('new@example.org', Users::find($id)['email']);
        same([[], true], Accounts::updateProfile($user, ['email' => 'anna@example.com', 'current' => 'newpassword'] + $data, 'en'));
        same(2, count($sent), 'no link to an address another account has');

        same(t('ACCOUNT_ERR_CURRENT'), Accounts::deleteOwn($user, 'wrong'));
        same(t('ACCOUNT_ERR_DELETE_ADMIN'), Accounts::deleteOwn(Users::find($admin), 'oldpassword'));
        same(null, Accounts::deleteOwn($user, 'newpassword'));
        same(null, Users::find($id));
    },

    'users: registered users never count as the last administrator' => function () {
        accounts_fixture();
        $admin = mail_user();
        $reg = site_user();
        $base = ['name' => 'Anna', 'username' => 'anna', 'email' => 'anna@example.com', 'password' => '', 'password2' => '', 'active' => true, 'language' => ''];

        [, $errors] = Users::save($admin, ['admin' => false] + $base, $admin);
        same(['admin'], array_keys($errors), 'not from yourself');
        [, $errors] = Users::save($admin, ['admin' => false] + $base, $reg);
        same(['admin'], array_keys($errors), 'nor from the last administrator');
        same(t('USERS_ERR_LAST_ACTIVE'), $errors['admin']);
        same([0, t('USERS_ERR_LAST_ACTIVE')], Users::setActive([$admin], false, $reg));
        same([0, t('USERS_ERR_LAST_ACTIVE')], Users::delete([$admin], $reg));

        same([1, ''], Users::setActive([$reg], false, $admin), 'registered users can be blocked');
        [, $errors] = Users::save($reg, ['name' => 'Reg', 'username' => 'reg', 'email' => 'reg@example.com', 'password' => '', 'password2' => '',
            'active' => true, 'admin' => true, 'language' => ''], $admin);
        same([], $errors);
        same(1, (int) Users::find($reg)['admin'], 'promoted');
        same([1, ''], Users::delete([$admin], $reg), 'now another administrator remains');

        $state = ['search' => '', 'status' => '', 'kind' => 'registered', 'sort' => 'name', 'dir' => 'asc'];
        same([], Users::list($state, 0, 0)['items']);
        same(['reg'], array_column(Users::list(['kind' => 'admin'] + $state, 0, 0)['items'], 'username'));
    },

    'password reset: from the site for every account, from the back end for administrators' => function () {
        $sent = accounts_fixture();
        mail_user();
        site_user();
        PasswordReset::request('reg');
        same(0, count($sent), 'the back end sends no link to a registered user');
        PasswordReset::request('reg', 'en');
        same('reg@example.com', $sent[0]['to']);
        $token = account_token($sent[0], 'reset');
        same([], PasswordReset::complete($token, 'resetpassword', 'resetpassword'));
        same(null, Auth::login('reg', 'resetpassword', true));
        PasswordReset::request('anna', 'en');
        check(str_contains($sent[1]['text'], '/cms/account/reset?token='), 'administrators too, to the site form');
    },

    'account pages: addresses and the reserved first segment' => function () {
        accounts_fixture(['languages' => 'en,pl']);
        same('', Account::match('account', 'en'));
        same('', Account::match('account/', 'en'));
        same('login', Account::match('account/login', 'en'));
        same('register', Account::match('pl/account/register', 'pl'));
        same(null, Account::match('account/login', 'pl'));
        same(null, Account::match('account/nothing', 'en'));
        same(null, Account::match('accounts', 'en'));
        same('/cms/account/login', Account::url('login', 'en'));
        same('/cms/pl/account', Account::url('', 'pl'));
        check(Aliases::isReserved('account'));

        $_REQUEST = [];
        foreach (['/cms/o-nas' => '/cms/o-nas', 'https://evil.example/' => '/d', '//evil.example/x' => '/d',
                     '/cms/administrator/index.php' => '/d', '/other/' => '/d', "/cms/a\r\nb" => '/d'] as $return => $expected) {
            $_POST = ['return' => $return];
            same($expected, Account::returnUrl('/d'), $return);
        }
        $_POST = [];
    },
];
