<?php

use Pageyard\Auth;
use Pageyard\Database\Db;
use Pageyard\Database\Migrator;
use Pageyard\Pagination;
use Pageyard\Users;

defined('PAGEYARD') or exit;

/** A migrated in-memory database installed as Db::get(), with one active user (id 1). */
function users_db(): Db
{
    $db = memory_db();
    (new Migrator($db))->migrate();
    Db::setInstance($db);
    $db->insert('#__users', ['name' => 'First', 'username' => 'first', 'email' => 'first@example.com',
        'password' => password_hash('secret123', PASSWORD_DEFAULT), 'admin' => 1, 'created' => Db::now(), 'modified' => Db::now()]);
    $_SESSION = [];
    return $db;
}

function user_data(array $overrides = []): array
{
    return array_merge(['name' => 'Second', 'username' => 'Second', 'email' => 'Second@Example.com',
        'password' => 'password1', 'password2' => 'password1', 'active' => true, 'admin' => true, 'language' => ''], $overrides);
}

return [
    'template: a form with errors opens on the tab that has one, a save stays on the open tab' => function () {
        $open = Pageyard\Admin\Controllers\TemplateController::openTab(...);
        same('layout', $open([], ''));
        same('files', $open([], 'files'), 'asked for');
        same('layout', $open([], 'nonsense'));
        same('css', $open(['custom_css' => 'x'], 'files'), 'an error beats what was asked for');
        same('code', $open(['code_body' => 'x'], ''));
        same('look', $open(['logo' => 'x', 'code_head' => 'x'], ''), 'the first in tab order');
    },

    'configuration: a form with errors opens on the first tab that has one' => function () {
        $open = Pageyard\Admin\Controllers\ConfigController::openTab(...);
        same('site', $open([], ''));
        same('mail', $open([], 'mail'), 'asked for');
        same('site', $open([], 'nonsense'));
        same('mail', $open(['mail_from' => 'x'], ''));
        same('mail', $open(['smtp_host' => 'x', 'smtp_port' => 'x'], 'db'), 'an error beats what was asked for');
        same('mail', $open(['contact_email' => 'x'], ''));
        same('system', $open(['history_days' => 'x', 'mail_from' => 'x'], ''), 'the first in tab order');
        same('site', $open(['pl.meta_description' => 'x', 'image_max' => 'x'], ''));
        same('lang', $open(['admin_language' => 'x'], ''));
        same('system', $open(['live_site' => 'x'], ''));
    },

    'users: save validates and normalises' => function () {
        $db = users_db();
        [$id, $err] = Users::save(0, user_data(), 1);
        same([], $err);
        same(2, $id);
        $row = Users::find(2);
        same('second', $row['username']);
        same('second@example.com', $row['email']);
        check(password_verify('password1', $row['password']));

        [, $err] = Users::save(0, user_data(['username' => 'FIRST', 'email' => 'first@example.com', 'password' => 'short']), 1);
        same(['username', 'email', 'password'], array_keys($err));
        [, $err] = Users::save(0, user_data(['name' => ' ', 'username' => 'a b', 'email' => 'x', 'password2' => 'other']), 1);
        same(['name', 'username', 'email', 'password2'], array_keys($err));

        // Editing without a password keeps the old one.
        [, $err] = Users::save(2, user_data(['name' => 'Renamed', 'password' => '', 'password2' => '']), 1);
        same([], $err);
        same('Renamed', Users::find(2)['name']);
        check(password_verify('password1', $db->fetchValue('SELECT password FROM #__users WHERE id = 2')));
    },

    'users: cannot block or delete yourself or the last active user' => function () {
        users_db();
        Users::save(0, user_data(), 1);
        [, $err] = Users::save(1, user_data(['username' => 'first', 'email' => 'first@example.com', 'password' => '', 'active' => false]), 1);
        same(['active'], array_keys($err));

        same([0, t('USERS_ERR_BLOCK_SELF')], Users::setActive([1, 2], false, 1));
        same([1, ''], Users::setActive([2], false, 1));
        // User 2 is blocked; logged in as 2 (theoretically), blocking 1 would leave nobody active.
        same([0, t('USERS_ERR_LAST_ACTIVE')], Users::setActive([1], false, 2));
        same([0, t('USERS_ERR_DELETE_SELF')], Users::delete([1], 1));
        same([0, t('USERS_ERR_LAST_ACTIVE')], Users::delete([1], 2));
        same([1, ''], Users::delete([2], 1));
        same(null, Users::find(2));
    },

    'users: list search, filter, sort and paging' => function () {
        users_db();
        foreach (['Zofia' => 'zofia', 'Adam' => 'adam', 'Ewa_x' => 'ewa'] as $name => $login) {
            Users::save(0, user_data(['name' => $name, 'username' => $login, 'email' => "$login@example.com"]), 1);
        }
        Users::setActive([3], false, 1);
        $state = ['search' => '', 'status' => '', 'sort' => 'name', 'dir' => 'asc'];
        same(['Adam', 'Ewa_x', 'First', 'Zofia'], array_column(Users::list($state, 0, 0)['items'], 'name'));
        same(['Zofia', 'First'], array_column(Users::list(['dir' => 'desc'] + $state, 2, 0)['items'], 'name'));
        same(4, Users::list($state, 2, 0)['total']);
        same(['Adam'], array_column(Users::list(['status' => '0'] + $state, 0, 0)['items'], 'name'));
        same(['Zofia'], array_column(Users::list(['search' => 'ZOF'] + $state, 0, 0)['items'], 'name'));
        same(['Ewa_x'], array_column(Users::list(['search' => '_'] + $state, 0, 0)['items'], 'name'), 'LIKE wildcards are literal');
        same(['First'], array_column(Users::list(['search' => 'id:1'] + $state, 0, 0)['items'], 'name'));
        same(['Adam', 'Ewa_x', 'First', 'Zofia'], array_column(Users::list(['sort' => 'password; --'] + $state, 0, 0)['items'], 'name'));
    },

    'auth: login, wrong password, blocked user, throttling' => function () {
        $db = users_db();
        same(null, Auth::user());
        same(t('LOGIN_FAILED'), Auth::login('first', 'wrong'));
        same(t('LOGIN_FAILED'), Auth::login('nobody', 'secret123'));
        same(null, Auth::login(' FIRST ', 'secret123'));
        same(1, Auth::id());
        same(0, (int) $db->fetchValue("SELECT COUNT(*) FROM #__login_attempts WHERE username = 'first'"));
        check($db->fetchValue('SELECT last_login FROM #__users WHERE id = 1') !== null);

        Auth::logout();
        same(null, Auth::user());
        same(null, Auth::login('first@example.com', 'secret123'), 'e-mail works as the login');

        $db->update('#__users', ['active' => 0], ['id' => 1]);
        same(null, Auth::user(), 'a blocked user loses the session');
        same(t('LOGIN_BLOCKED'), Auth::login('first', 'secret123'));
        $db->update('#__users', ['active' => 1], ['id' => 1]);

        for ($i = 0; $i < Auth::MAX_PER_USER; $i++) {
            Auth::login('first', 'wrong');
        }
        same(t('LOGIN_TOO_MANY', Auth::WINDOW), Auth::login('first', 'secret123'), 'locked even with the right password');
        $db->exec("UPDATE #__login_attempts SET created = '2000-01-01 00:00:00'");
        same(null, Auth::login('first', 'secret123'), 'old attempts expire');
    },

    'auth: session expires after the configured idle time' => function () {
        users_db();
        Auth::login('first', 'secret123');
        $_SESSION['last_seen'] = time() - (int) Pageyard\Config::get('session_lifetime', 60) * 60 - 1;
        same(null, Auth::user());
        same([], array_diff_key($_SESSION, ['flash' => 1]));
    },

    'pagination: window and offset' => function () {
        $p = new Pagination(95, 10, 5, fn ($n) => "?page=$n");
        same(10, $p->pages);
        same(40, $p->offset());
        same([1, 0, 3, 4, 5, 6, 7, 0, 10], $p->window());
        same(10, (new Pagination(95, 10, 99, fn ($n) => ''))->page);
        same(1, (new Pagination(0, 10, 3, fn ($n) => ''))->page);
        same('', (new Pagination(5, 0, 1, fn ($n) => ''))->render());
        check(str_contains($p->render(), 'href="?page=6"'));
    },

    'lang: every key used in code exists in en and pl' => function () {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PY_ROOT, FilesystemIterator::SKIP_DOTS));
        $used = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_ends_with($path, '.php') || preg_match('~/(language|tests|data|media|dist)/~', $path)) {
                continue;
            }
            preg_match_all("/\\bt\\('([A-Z0-9_]+)'/", file_get_contents($path), $m);
            $used = array_merge($used, $m[1]);
        }
        $load = function (string $tag): array {
            $all = [];
            foreach (glob(PY_ROOT . "/language/$tag/*.php") as $f) {
                if (basename($f) !== 'meta.php') {
                    $all += require $f;
                }
            }
            return $all;
        };
        $en = $load('en');
        $pl = $load('pl');
        same([], array_values(array_diff(array_unique($used), array_keys($en))), 'missing in en');
        same([], array_values(array_diff(array_keys($en), array_keys($pl))), 'missing in pl');
        same([], array_values(array_diff(array_keys($pl), array_keys($en))), 'only in pl');
        foreach ($en as $key => $text) {
            same(substr_count($text, '%'), substr_count($pl[$key], '%'), "placeholders in $key");
        }
    },
];
