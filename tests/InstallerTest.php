<?php

use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Installer;

defined('PAGEYARD') or exit;

function install_input(array $overrides = []): array
{
    return array_merge(Installer::FIELDS, [
        'sitename' => 'Test site', 'language' => 'pl', 'languages' => ['en'], 'timezone' => 'Europe/Warsaw',
        'admin_name' => 'Admin', 'admin_username' => 'Admin', 'admin_email' => 'Admin@Example.com',
        'admin_password' => 'secret123', 'admin_password2' => 'secret123',
    ], $overrides);
}

/** Reads a written configuration.php without declaring the class in this process. */
function read_config(string $file): array
{
    $src = file_get_contents($file);
    preg_match_all('/public \$(\w+) = (.*);/', $src, $m, PREG_SET_ORDER);
    $values = [];
    foreach ($m as [, $name, $value]) {
        $values[$name] = eval("return $value;");
    }
    return $values;
}

return [
    'installer: validation' => function () {
        $i = new Installer(temp_root());
        same([], $i->validate(install_input()));
        $err = $i->validate(install_input([
            'sitename' => ' ', 'dbprefix' => 'Py_', 'admin_username' => 'a b', 'admin_email' => 'nope',
            'admin_password2' => 'other', 'timezone' => 'Mars/Base',
        ]));
        same(['sitename', 'timezone', 'dbprefix', 'admin_username', 'admin_email', 'admin_password2'], array_keys($err));
        $err = $i->validate(install_input(['dbtype' => 'mysql', 'host' => '', 'port' => '99999', 'db' => 'a-b']));
        same(['host', 'port', 'db'], array_keys($err));
    },

    'installer: SQLite install writes config, database and admin' => function () {
        $root = temp_root();
        (new Installer($root))->install(install_input());

        $cfg = read_config("$root/configuration.php");
        same('Test site', $cfg['sitename']);
        same('pl', $cfg['language']);
        same('pl,en', $cfg['languages']);
        same('sqlite', $cfg['dbtype']);
        same(false, $cfg['debug']);
        same(32, strlen($cfg['secret']));
        check(preg_match('~^data/pageyard-[0-9a-f]{12}\.sqlite$~', $cfg['db_path']) === 1, $cfg['db_path']);

        $db = Db::connect($cfg, $root);
        $user = $db->fetchRow('SELECT * FROM #__users');
        same('admin', $user['username']);
        same('admin@example.com', $user['email']);
        check(password_verify('secret123', $user['password']));
        same((new Pageyard\Database\Migrator($db))->all(), $db->fetchColumn('SELECT name FROM #__migrations ORDER BY name'));
        unset($db);

        throws(fn () => (new Installer($root))->install(install_input()), RuntimeException::class, 'configuration.php');
    },

    'installer: refuses an existing table prefix' => function () {
        $opt = mysql_options();
        if (!$opt) {
            return 'skip';
        }
        $name = 'pageyard_test_' . bin2hex(random_bytes(3));
        $input = install_input(['dbtype' => 'mysql', 'host' => $opt['host'], 'port' => (string) $opt['port'],
            'user' => $opt['user'], 'password' => $opt['password'], 'db' => $name]);
        $server = Db::connect($opt);
        try {
            $root = temp_root();
            (new Installer($root))->install($input);  // creates the database itself
            same('mysql', read_config("$root/configuration.php")['dbtype']);
            throws(fn () => (new Installer(temp_root()))->install($input), RuntimeException::class, 'py_');
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `$name`");
        }
    },

    'config: write produces loadable PHP' => function () {
        $file = temp_root() . '/configuration.php';
        Config::write(['sitename' => "O'Reilly \$x", 'port' => '3307', 'debug' => 1, 'bogus' => 'x'], $file);
        $cfg = read_config($file);
        same("O'Reilly \$x", $cfg['sitename']);
        same(3307, $cfg['port']);
        same(true, $cfg['debug']);
        check(!isset($cfg['bogus']));
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $out, $code);
        same(0, $code, implode("\n", $out));
    },
];
