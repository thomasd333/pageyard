<?php

use Pageyard\Backup;
use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Database\Migrator;
use Pageyard\Log;

defined('PAGEYARD') or exit;

/** A real SQLite file in a temp root with one page title; Backup::$dir points into it. */
function backup_fixture(array $config = []): string
{
    $root = temp_root();
    Config::set($config + ['dbtype' => 'sqlite', 'db_path' => "$root/data/site.sqlite", 'backup_interval' => 24, 'backup_keep' => 2]);
    $db = Db::connect(Config::all());
    Db::setInstance($db);
    (new Migrator($db))->migrate();
    $db->insert('#__settings', ['name' => 'marker', 'value' => '"original"']);
    Backup::$dir = "$root/data/backups";
    mkdir(Backup::$dir);
    Log::$file = "$root/data/test.log";
    return $root;
}

/** Unpacks a backup and reads the marker setting from it. */
function backup_marker(string $name): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'pyb');
    file_put_contents($tmp, gzdecode((string) file_get_contents((string) Backup::path($name))));
    $pdo = new PDO('sqlite:' . $tmp);
    $value = (string) $pdo->query("SELECT value FROM py_settings WHERE name = 'marker'")->fetchColumn();
    $check = $pdo->query('PRAGMA integrity_check')->fetchColumn();
    $pdo = null;
    unlink($tmp);
    return "$value/$check";
}

/** Sets the marker setting in the live database. */
function set_marker(string $value): void
{
    Db::get()->update('#__settings', ['value' => json_encode($value)], ['name' => 'marker']);
}

function live_marker(): mixed
{
    return json_decode((string) Db::get()->fetchValue("SELECT value FROM #__settings WHERE name = 'marker'"));
}

/** A gzip backup of a database file made by $change on a copy of the live data, named as $name. */
function altered_backup(string $name, ?\Closure $change): void
{
    $tmp = Backup::$dir . '/x.sqlite';
    Db::get()->pdo()->exec('VACUUM INTO ' . Db::get()->pdo()->quote($tmp));
    $pdo = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($change !== null) {
        $change($pdo);
    }
    $pdo = null;
    file_put_contents(Backup::$dir . "/$name", gzencode((string) file_get_contents($tmp)));
    unlink($tmp);
}

function add_active_user(): void
{
    Db::get()->insert('#__users', ['name' => 'A', 'username' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'active' => 1, 'admin' => 1, 'created' => Db::now(), 'modified' => Db::now()]);
}

return [
    'restore: brings back the data, ids stay unused, and it can be undone' => function () {
        $root = backup_fixture();
        add_active_user();
        Db::get()->insert('#__revisions', ['item_type' => 'page', 'item_id' => 1, 'reason' => 'save', 'version_date' => Db::now(), 'created' => Db::now(), 'data' => '{}']);
        $name = Backup::create('manual');
        Db::get()->delete('#__revisions', ['item_id' => 1]);

        set_marker('changed');
        $added = Db::get()->insert('#__revisions', ['item_type' => 'page', 'item_id' => 2, 'reason' => 'save', 'version_date' => Db::now(), 'created' => Db::now(), 'data' => '{}']);
        Db::get()->exec('CREATE TABLE #__leftover (id INT)');

        $result = Backup::restore($name);
        same('original', live_marker());
        same(null, $result['damaged']);
        same(1, (int) Db::get()->fetchValue('SELECT item_id FROM #__revisions'));
        check(!Db::get()->tableExists('#__leftover'), 'tables not in the backup go');
        check((bool) Db::get()->fetchValue("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name LIKE '%users%'"), 'indexes are back');
        same('"changed"/ok', backup_marker((string) $result['safety']), 'the state before');
        check(str_starts_with((string) $result['safety'], 'restore-'));
        same([], glob(Backup::$dir . '/.tmp-*'), 'no temporary files');

        // The AUTOINCREMENT counters come from the backup too.
        $next = Db::get()->insert('#__revisions', ['item_type' => 'page', 'item_id' => 3, 'reason' => 'save', 'version_date' => Db::now(), 'created' => Db::now(), 'data' => '{}']);
        same($added, $next, 'the counter is the one from the backup');

        Backup::restore((string) $result['safety']);
        same('changed', live_marker(), 'undone');
        check(Db::get()->tableExists('#__leftover'));
        same('ok', Db::get()->fetchValue('PRAGMA integrity_check'));
        unset($root);
    },

    'restore: refuses bad backups and changes nothing' => function () {
        backup_fixture();
        add_active_user();
        set_marker('live');
        $cases = [
            'manual-20260101-000001.sqlite.gz' => [fn ($p) => $p->exec('DROP TABLE py_users'), 'BACKUPS_ERR_FOREIGN'],
            'manual-20260101-000002.sqlite.gz' => [fn ($p) => $p->exec('UPDATE py_users SET active = 0'), 'BACKUPS_ERR_NO_USER'],
            'manual-20260101-000003.sqlite.gz' => [fn ($p) => $p->exec("INSERT INTO py_migrations (name, applied) VALUES ('9999_future', '2026-01-01 00:00:00')"), 'BACKUPS_ERR_NEWER'],
        ];
        foreach ($cases as $name => [$change, $key]) {
            altered_backup($name, $change);
            throws(fn () => Backup::restore($name), RuntimeException::class, t($key));
        }
        file_put_contents(Backup::$dir . '/manual-20260101-000004.sqlite.gz', gzencode('this is not a database'));
        throws(fn () => Backup::restore('manual-20260101-000004.sqlite.gz'), Throwable::class);
        file_put_contents(Backup::$dir . '/manual-20260101-000005.sqlite.gz', 'not even gzip');
        throws(fn () => Backup::restore('manual-20260101-000005.sqlite.gz'), Throwable::class);
        throws(fn () => Backup::restore('../../configuration.php'), RuntimeException::class);
        throws(fn () => Backup::restore('manual-20990101-000000.sqlite.gz'), RuntimeException::class);

        same('live', live_marker());
        same([], glob(Backup::$dir . '/restore-*'), 'no state saved for nothing');
        same([], glob(Backup::$dir . '/.tmp-*'));
    },

    'restore: a damaged database is set aside' => function () {
        $root = backup_fixture();
        add_active_user();
        $name = Backup::create('manual');
        $file = "$root/data/site.sqlite";
        Db::get()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        Db::setInstance(null);
        gc_collect_cycles();
        @unlink("$file-wal");
        @unlink("$file-shm");
        file_put_contents($file, str_repeat('garbage ', 4096));

        $result = Backup::restore($name);
        same(null, $result['safety']);
        check((bool) preg_match('/^site\.sqlite\.damaged-\d{8}-\d{6}$/', (string) $result['damaged']), (string) $result['damaged']);
        check(str_starts_with((string) file_get_contents("$root/data/" . $result['damaged']), 'garbage'), 'kept as it was');
        same('original', live_marker());
        same('ok', Db::get()->fetchValue('PRAGMA integrity_check'));
        Db::setInstance(null);
    },

    'restore: an older backup gets its migrations again' => function () {
        backup_fixture();
        add_active_user();
        altered_backup('manual-20260101-000001.sqlite.gz', function ($p) {
            $p->exec('DROP TABLE py_rate_limits');
            $p->exec('DROP TABLE py_password_resets');
            $p->exec("DELETE FROM py_migrations WHERE name = '0010_mail_limits'");
        });
        Backup::restore('manual-20260101-000001.sqlite.gz');
        check(!Db::get()->tableExists('#__rate_limits'));
        $migrator = new Migrator(Db::get());
        same(['0010_mail_limits'], $migrator->pending());
        $migrator->migrate();
        check(Db::get()->tableExists('#__rate_limits'));
    },

    'backup: consistent copy, checked and compressed' => function () {
        $root = backup_fixture();
        $name = Backup::create('manual');
        check((bool) preg_match('/^manual-\d{8}-\d{6}\.sqlite\.gz$/', (string) $name), (string) $name);
        same('"original"/ok', backup_marker($name));
        // Later changes don't touch the copy.
        Db::get()->update('#__settings', ['value' => '"changed"'], ['name' => 'marker']);
        same('"original"/ok', backup_marker($name));
        same(['manual'], array_column(Backup::list(), 'kind'));
        $status = Backup::status();
        same([true, $name], [$status['ok'], $status['message']]);
        same([], glob("$root/data/backups/.tmp-*"), 'no temporary files left');
        same(null, Backup::checkLive());
        Db::setInstance(null);
        Backup::$dir = null;
    },

    'backup: names, deleting and pruning' => function () {
        $root = backup_fixture();
        same(null, Backup::path('../site.sqlite'));
        same(null, Backup::path('auto-20260101-000000.sqlite.gz'), 'missing');
        foreach (['auto-20260101-000000', 'auto-20260102-000000', 'auto-20260103-000000', 'manual-20260101-000000', 'update-20251231-000000'] as $n) {
            file_put_contents("$root/data/backups/$n.sqlite.gz", 'x');
        }
        file_put_contents("$root/data/backups/notes.sqlite.gz", 'x');
        same(['auto-20260103-000000.sqlite.gz', 'auto-20260102-000000.sqlite.gz', 'manual-20260101-000000.sqlite.gz',
            'auto-20260101-000000.sqlite.gz', 'update-20251231-000000.sqlite.gz'], array_column(Backup::list(), 'name'), 'newest first');
        same(1, Backup::prune(2), 'only automatic ones, oldest first');
        same(['auto', 'auto', 'manual', 'update'], array_column(Backup::list(), 'kind'));
        same(1, Backup::delete(['manual-20260101-000000.sqlite.gz', '../site.sqlite', 'notes.sqlite.gz']));
        check(is_file("$root/data/site.sqlite") && is_file("$root/data/backups/notes.sqlite.gz"));
        Db::setInstance(null);
        Backup::$dir = null;
    },

    'backup: automatic only when due and changed' => function () {
        $root = backup_fixture();
        $first = Backup::runIfDue();
        check($first !== null && str_starts_with($first, 'auto-'), 'first one right away');
        same(null, Backup::runIfDue(), 'not due yet');

        // Pretend the last backup is two days old.
        $old = 'auto-' . gmdate('Ymd-His', time() - 2 * 86400) . '.sqlite.gz';
        rename("$root/data/backups/$first", "$root/data/backups/$old");
        $db = "$root/data/site.sqlite";
        touch($db, time() - 3 * 86400);
        @touch("$db-wal", time() - 3 * 86400);
        same(null, Backup::runIfDue(), 'nothing changed since');
        touch($db);
        check(Backup::runIfDue() !== null, 'changed and due');

        Config::set(['backup_interval' => 0] + Config::all());
        touch($db, time() + 10);
        same(null, Backup::runIfDue(), 'switched off');
        Config::set(['dbtype' => 'mysql'] + Config::all());
        same(false, Backup::supported());
        Db::setInstance(null);
        Backup::$dir = null;
    },
];
