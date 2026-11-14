<?php

use Pageyard\Database\Db;
use Pageyard\Database\Dialect;
use Pageyard\Database\Migrator;
use Pageyard\Database\MysqlDialect;
use Pageyard\Database\Schema;
use Pageyard\Database\SqliteDialect;

defined('PAGEYARD') or exit;

function memory_db(): Db
{
    return Db::connect(['dbtype' => 'sqlite', 'db_path' => ':memory:', 'dbprefix' => 't_']);
}

/** Connection options from PY_TEST_MYSQL, or null when MySQL tests are off. */
function mysql_options(): ?array
{
    $env = getenv('PY_TEST_MYSQL');
    if (!$env || !preg_match('/^([^:@]+)(?::([^@]*))?@([^:]+)(?::(\d+))?$/', $env, $m)) {
        return null;
    }
    return ['dbtype' => 'mysql', 'user' => $m[1], 'password' => $m[2] ?? '', 'host' => $m[3],
        'port' => (int) ($m[4] ?? 3306), 'db' => '', 'dbprefix' => 't_'];
}

return [
    'dialect: parses column specs' => function () {
        same(['type' => 'string', 'length' => 150, 'null' => false, 'default' => null, 'primary' => false],
            Dialect::parse('string(150)'));
        same(['type' => 'bool', 'length' => null, 'null' => false, 'default' => '1', 'primary' => false],
            Dialect::parse('bool default 1'));
        same(['type' => 'string', 'length' => 20, 'null' => true, 'default' => "'it''s'", 'primary' => false],
            Dialect::parse("string(20) null default 'it''s'"));
        same(true, Dialect::parse('string(100) primary')['primary']);
        throws(fn () => Dialect::parse('varchar(10)'), InvalidArgumentException::class);
        throws(fn () => Dialect::parse('string'), InvalidArgumentException::class, 'length');
        throws(fn () => Dialect::parse('int unsigned'), InvalidArgumentException::class, 'unsigned');
    },

    'dialect: CREATE TABLE for SQLite and MySQL' => function () {
        $cols = ['id' => 'id', 'title' => 'string(255)', 'body' => 'mediumtext null', 'hits' => 'int default 0'];
        same("CREATE TABLE \"p\" (\n  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n  \"title\" TEXT NOT NULL,\n"
            . "  \"body\" TEXT NULL,\n  \"hits\" INTEGER NOT NULL DEFAULT 0\n)",
            (new SqliteDialect())->createTable('p', $cols));
        same("CREATE TABLE `p` (\n  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n  `title` VARCHAR(255) NOT NULL,\n"
            . "  `body` MEDIUMTEXT NULL,\n  `hits` INT NOT NULL DEFAULT 0\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            (new MysqlDialect())->createTable('p', $cols));
        same('CREATE UNIQUE INDEX `p_a_b_uq` ON `p` (`a`, `b`)', (new MysqlDialect())->createIndex('p', ['a', 'b'], true));
    },

    'db: prefix, CRUD and fetch helpers' => function () {
        $db = memory_db();
        same('t_users', $db->table('#__users'));
        (new Schema($db))->createTable('#__items', ['id' => 'id', 'name' => 'string(50)', 'n' => 'int null'],
            ['unique' => [['name']]]);
        check($db->tableExists('#__items'));
        check(!$db->tableExists('#__nope'));

        same(1, $db->insert('#__items', ['name' => 'a', 'n' => 5]));
        same(2, $db->insert('#__items', ['name' => 'b', 'n' => null]));
        same(['id' => 1, 'name' => 'a', 'n' => 5], $db->fetchRow('SELECT * FROM #__items WHERE id = ?', [1]));
        same(['a', 'b'], $db->fetchColumn('SELECT name FROM #__items ORDER BY id'));
        same('b', $db->fetchValue('SELECT name FROM #__items WHERE n IS NULL'));
        same(null, $db->fetchRow('SELECT * FROM #__items WHERE id = :id', ['id' => 99]));
        same(1, $db->update('#__items', ['n' => 7], ['n' => null]));
        same(7, $db->fetchValue('SELECT n FROM #__items WHERE id = 2'));
        same(1, $db->delete('#__items', ['id' => 1]));
        throws(fn () => $db->delete('#__items', []), InvalidArgumentException::class, 'WHERE');
        throws(fn () => $db->insert('#__items', ['name' => 'b']), PDOException::class);
    },

    'db: transaction rolls back on error' => function () {
        $db = memory_db();
        (new Schema($db))->createTable('#__items', ['id' => 'id', 'name' => 'string(50)']);
        throws(fn () => $db->transaction(function (Db $db) {
            $db->insert('#__items', ['name' => 'x']);
            throw new RuntimeException('boom');
        }), RuntimeException::class, 'boom');
        same(0, $db->fetchValue('SELECT COUNT(*) FROM #__items'));
        same(5, $db->transaction(fn () => 5));
    },

    'migrator: applies pending migrations once' => function () {
        $db = memory_db();
        $m = new Migrator($db);
        $all = $m->all();
        same('0001_initial', $all[0]);
        same($all, $m->pending());
        same($all, $m->migrate());
        same([], $m->migrate());
        check($db->tableExists('#__users') && $db->tableExists('#__settings'));
    },

    'mysql: migrations and CRUD on a scratch database' => function () {
        if (!($opt = mysql_options())) {
            return 'skip';
        }
        $name = 'pageyard_test_' . bin2hex(random_bytes(3));
        $server = Db::connect($opt);
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
        try {
            $db = Db::connect(['db' => $name] + $opt);
            same((new Migrator($db))->all(), (new Migrator($db))->migrate());
            $id = $db->insert('#__users', ['name' => 'Zażółć', 'username' => 'a', 'email' => 'a@x', 'password' => 'h',
                'created' => Db::now(), 'modified' => Db::now()]);
            same(1, $id);
            same('Zażółć', $db->fetchValue('SELECT name FROM #__users WHERE id = ?', [$id]));
            same(1, $db->fetchValue('SELECT active FROM #__users WHERE id = ?', [$id]));
            same(null, $db->fetchValue('SELECT last_login FROM #__users WHERE id = ?', [$id]));
            $db->insert('#__settings', ['name' => 'x', 'value' => str_repeat('a', 70000)]);
            same(70000, strlen($db->fetchValue('SELECT value FROM #__settings WHERE name = ?', ['x'])));
        } finally {
            $server->exec("DROP DATABASE `$name`");
        }
    },
];
