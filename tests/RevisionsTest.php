<?php

use Pageyard\Blocks;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Database\Migrator;
use Pageyard\Pages;
use Pageyard\Revisions;
use Pageyard\Settings;

defined('PAGEYARD') or exit;

function history_db(array $config = []): Db
{
    $db = memory_db();
    (new Migrator($db))->migrate();
    history_setup($db, $config);
    return $db;
}

function history_setup(Db $db, array $config = []): void
{
    Db::setInstance($db);
    Config::set($config + ['language' => 'pl', 'languages' => 'pl,en', 'timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Settings::reset();
    Revisions::$now = strtotime('2026-11-04 12:00:00 UTC');
}

function history_page(string $body, array $overrides = [], string $title = 'Strona'): array
{
    return array_merge(['state' => 1, 'category_id' => 0, 'image' => '', 'publish_up' => '2026-01-01T10:00',
        'translations' => ['pl' => ['title' => $title, 'alias' => '', 'intro' => '', 'body' => "<p>$body</p>", 'meta_description' => '']]], $overrides);
}

/** Bodies of an item's revisions, newest first. */
function history_bodies(int $pageId): array
{
    return array_map(fn ($r) => Revisions::find((int) $r['id'])['content']['translations']['pl']['body'], Revisions::forItem('page', $pageId));
}

function history_workflow(Db $db): void
{
    history_setup($db);
    [$cat] = Categories::save(0, ['state' => 1, 'translations' => ['pl' => ['title' => 'Kategoria', 'alias' => '']]]);
    [$id] = Pages::save(0, history_page('v1', ['category_id' => $cat]), 1);
    same([], Revisions::forItem('page', $id), 'a new page has no history');

    Pages::save($id, history_page('v2', ['category_id' => $cat]), 1);
    same(['<p>v1</p>'], history_bodies($id));
    same($cat, Revisions::find((int) Revisions::forItem('page', $id)[0]['id'])['content']['category_id']);

    [$restored, $err] = Pages::restore($id, Revisions::find((int) Revisions::forItem('page', $id)[0]['id'])['content'], [], 1);
    same([], $err);
    same($id, $restored);
    same('<p>v1</p>', Pages::find($id)['translations']['pl']['body']);

    Pages::delete([$id]);
    $deleted = Revisions::deleted();
    same([$id], array_map(fn ($r) => (int) $r['item_id'], $deleted));
    $result = Revisions::restore((int) $deleted[0]['id'], 1);
    same([], $result[2]);
    same(true, $result[4]);
    same($id, $result[1]);
    same(0, (int) Pages::find($id)['state']);
    same($cat, (int) Pages::find($id)['category_id']);
    same([], Revisions::deleted());
}

return [
    'history: keeps the replaced version, with its own date and author' => function () {
        $db = history_db();
        $db->insert('#__users', ['name' => 'Ann', 'username' => 'ann', 'email' => 'a@example.com', 'password' => 'x', 'created' => Db::now(), 'modified' => Db::now()]);
        [$id] = Pages::save(0, history_page('v1'), 1);
        $db->update('#__pages', ['modified' => '2026-08-01 08:00:00'], ['id' => $id]);
        same([], Revisions::forItem('page', $id));

        Pages::save($id, history_page('v2'), 1);
        $rows = Revisions::forItem('page', $id);
        same(1, count($rows));
        same('2026-08-01 08:00:00', $rows[0]['version_date']);
        same('2026-11-04 12:00:00', $rows[0]['created']);
        same('Ann', $rows[0]['author']);
        same('Strona', $rows[0]['title']);
        same('<p>v1</p>', history_bodies($id)[0]);
    },

    'history: retention counts from the replacement, so an old version survives the first save' => function () {
        $db = history_db();
        [$id] = Pages::save(0, history_page('v1'), 1);
        $db->update('#__pages', ['modified' => '2026-01-01 08:00:00'], ['id' => $id]);
        Pages::save($id, history_page('v2'), 1);
        same(0, Revisions::purge());
        same(1, Revisions::count('page', $id), 'a version dated months ago is still there');

        Revisions::$now += 7 * 86400 - 1;
        same(0, Revisions::purge());
        same('2026-11-11 12:00:00', Revisions::expires(Revisions::forItem('page', $id)[0]['created']));
        Revisions::$now += 2;
        same(1, Revisions::purge());
        same(0, Revisions::count('page', $id));
    },

    'history: saves without a content change leave nothing' => function () {
        history_db();
        [$id] = Pages::save(0, history_page('v1'), 1);
        Pages::save($id, history_page('v1'), 1);
        Pages::save($id, history_page('v1', ['state' => 0, 'publish_up' => '2026-02-02T10:00']), 1);
        Pages::setState([$id], 1, 1);
        same(0, Revisions::count('page', $id), 'status and date are not content');

        Pages::save($id, history_page('v1', ['image' => 'images/a.png']), 1);
        same(1, Revisions::count('page', $id), 'the image is content');
    },

    'history: frequent saves keep the start, checkpoints and the direct predecessor' => function () {
        history_db();
        [$id] = Pages::save(0, history_page('v0'), 1);
        // A save every minute: v0 is replaced at minute 0, v1 at minute 1, ...
        for ($minute = 0; $minute <= 25; $minute++) {
            Pages::save($id, history_page('v' . ($minute + 1)), 1);
            Revisions::$now += 60;
        }
        same(['<p>v25</p>', '<p>v20</p>', '<p>v10</p>', '<p>v0</p>'], history_bodies($id));
    },

    'history: thinning never loses the version right before a bad save' => function () {
        history_db();
        [$id] = Pages::save(0, history_page('good0'), 1);
        Pages::save($id, history_page('good1'), 1);
        Revisions::$now += 60;
        Pages::save($id, history_page('good2'), 1);
        Revisions::$now += 60;
        Pages::save($id, history_page('broken'), 1);
        same('<p>good2</p>', history_bodies($id)[0]);
    },

    'history: interval 0 keeps every save, days 0 switches it off and purges' => function () {
        history_db(['history_interval' => 0]);
        [$id] = Pages::save(0, history_page('a'), 1);
        foreach (['b', 'c', 'd', 'e'] as $body) {
            Pages::save($id, history_page($body), 1);
        }
        same(4, Revisions::count('page', $id));

        Config::set(['history_days' => 0, 'language' => 'pl', 'languages' => 'pl,en']);
        Pages::save($id, history_page('f'), 1);
        same(4, Revisions::count('page', $id), 'nothing new when off');
        Revisions::purge();
        same(0, Revisions::count('page', $id));
    },

    'history: the switch stops recording but keeps what is there until it expires' => function () {
        history_db(['history_interval' => 0]);
        [$id] = Pages::save(0, history_page('a'), 1);
        Pages::save($id, history_page('b'), 1);
        same(1, Revisions::count('page', $id));
        same(true, Revisions::enabled());

        Config::set(['history_enabled' => false, 'language' => 'pl', 'languages' => 'pl,en']);
        same(false, Revisions::enabled());
        same(true, Revisions::linked('page', $id), 'versions still there, so the form links to them');
        same(false, Revisions::linked('page', $id + 1));
        Pages::save($id, history_page('c'), 1);
        same(1, Revisions::count('page', $id), 'nothing new when off');

        Revisions::purge();
        same(1, Revisions::count('page', $id), 'switching off removes nothing');
        same(1, count(array_filter(Revisions::forItem('page', $id), fn ($r) => Revisions::restore((int) $r['id'], 1)[2] === [])), 'can still be restored');
        Revisions::$now = time() + 8 * 86400;
        Revisions::purge();
        same(0, Revisions::count('page', $id), 'expires as usual');
        Revisions::$now = null;
    },

    'history: restoring can be undone and is not thinned' => function () {
        history_db();
        [$id] = Pages::save(0, history_page('v1'), 1);
        Pages::save($id, history_page('v2'), 1);
        $first = (int) Revisions::forItem('page', $id)[0]['id'];

        $result = Revisions::restore($first, 1);
        same([], $result[2]);
        same('<p>v1</p>', Pages::find($id)['translations']['pl']['body']);
        same(['restore', 'save'], array_column(Revisions::forItem('page', $id), 'reason'));
        same(['<p>v2</p>', '<p>v1</p>'], history_bodies($id));

        Pages::save($id, history_page('v3'), 1);
        Pages::save($id, history_page('v4'), 1);
        check(in_array('<p>v2</p>', history_bodies($id), true), 'a version replaced by a restore stays');
    },

    'history: a restore keeps status and date; a taken alias or missing category stays as it is' => function () {
        $db = history_db();
        [$c1] = Categories::save(0, ['state' => 1, 'translations' => ['pl' => ['title' => 'Jeden', 'alias' => '']]]);
        [$c2] = Categories::save(0, ['state' => 1, 'translations' => ['pl' => ['title' => 'Dwa', 'alias' => '']]]);
        [$id] = Pages::save(0, history_page('v1', ['category_id' => $c1], 'Pierwsza'), 1);
        $rev = fn () => (int) Revisions::forItem('page', $id)[0]['id'];

        Pages::save($id, history_page('v2', ['category_id' => $c2, 'state' => 0, 'publish_up' => '2026-03-03T10:30'], 'Druga'), 1);
        $target = $rev();
        // Another page takes the old alias, the old category goes away.
        [$other] = Pages::save(0, history_page('x', [], 'Pierwsza'), 1);
        same('pierwsza', Pages::find($other)['translations']['pl']['alias']);
        Revisions::restore($target, 1);
        $page = Pages::find($id);
        same('Pierwsza', $page['translations']['pl']['title']);
        same('druga', $page['translations']['pl']['alias'], 'the alias now belongs to someone else');
        same($c1, (int) $page['category_id']);
        same(0, (int) $page['state'], 'status stays');
        same('2026-03-03 10:30:00', $page['publish_up'], 'date stays');

        Pages::save($id, history_page('v3', ['category_id' => $c2, 'state' => 1], 'Trzecia'), 1);
        $target = $rev();
        $db->update('#__pages', ['category_id' => $c1], ['id' => $id]);
        $db->update('#__categories', ['state' => 0], ['id' => $c2]);
        $db->delete('#__category_translations', ['category_id' => $c2]);
        $db->delete('#__categories', ['id' => $c2]);
        same([], Revisions::restore($target, 1)[2]);
        same($c1, (int) Pages::find($id)['category_id'], 'the category no longer exists');
    },

    'history: a deleted page comes back unpublished under its id' => function () {
        history_db();
        [$id] = Pages::save(0, history_page('v1'), 1);
        Pages::save($id, history_page('v2'), 1);
        same(1, Pages::delete([$id]));
        same(null, Pages::find($id));
        same(2, Revisions::count('page', $id), 'history stays after deleting');
        $deleted = Revisions::deleted();
        same(1, count($deleted));
        same('delete', $deleted[0]['reason']);

        [$newer] = Pages::save(0, history_page('other', [], 'Inna'), 1);
        check($newer > $id, 'ids are not reused');

        $result = Revisions::restore((int) $deleted[0]['id'], 1);
        same([], $result[2]);
        same($id, $result[1]);
        same(true, $result[4]);
        $page = Pages::find($id);
        same(0, (int) $page['state']);
        same('<p>v2</p>', $page['translations']['pl']['body']);
        same('strona', $page['translations']['pl']['alias']);
        same([], Revisions::deleted());
    },

    'history: HTML blocks and their restore; menu blocks have none' => function () {
        history_db();
        $data = fn (string $text, array $o = []) => array_merge(['title' => 'Blok', 'type' => 'html', 'position' => 'left', 'menu_id' => 0, 'state' => 1,
            'show_title' => false, 'assignment' => 'all', 'targets' => [], 'css_class' => '',
            'translations' => ['pl' => ['title' => '', 'content' => "<p>$text</p>"]]], $o);
        [$id] = Blocks::save(0, $data('a'));
        Blocks::save($id, $data('a'));
        same(0, Revisions::count('block', $id));

        Blocks::save($id, $data('b', ['position' => 'right', 'css_class' => 'x']));
        same(1, Revisions::count('block', $id), 'the old text changed');
        Blocks::save($id, $data('b', ['position' => 'left', 'css_class' => 'y']));
        same(1, Revisions::count('block', $id), 'settings are not content');

        Revisions::restore((int) Revisions::forItem('block', $id)[0]['id'], 1);
        $block = Blocks::find($id);
        same('<p>a</p>', $block['translations']['pl']['content']);
        same('left', $block['position'], 'position stays');
        same('y', $block['css_class']);

        Blocks::delete([$id]);
        $result = Revisions::restore((int) Revisions::deleted()[0]['id'], 1);
        same($id, $result[1]);
        same(0, (int) Blocks::find($id)['state']);
        same('y', Blocks::find($id)['css_class'], 'settings come back with a deleted block');

        $menu = Pageyard\Menus::save(0, 'M')[0];
        [$menuBlock] = Blocks::save(0, $data('', ['type' => 'menu', 'menu_id' => $menu, 'translations' => []]));
        Blocks::delete([$menuBlock]);
        same([], Revisions::deleted(), 'menu blocks are not kept');
    },

    'history: custom CSS' => function () {
        history_db();
        Settings::saveCustomCss('a {}');
        Settings::saveCustomCss('a {}');
        same(1, Revisions::count('css', 0), 'first change keeps the empty version');
        Revisions::$now += 3600;
        Settings::saveCustomCss('b {}');
        $rows = Revisions::forItem('css', 0);
        same(['a {}', ''], array_map(fn ($r) => Revisions::find((int) $r['id'])['content']['css'], $rows));

        same([], Revisions::restore((int) $rows[0]['id'], 1)[2]);
        same('a {}', Settings::get('custom_css'));
        same('restore', Revisions::forItem('css', 0)[0]['reason']);
    },

    'history: a broken history does not stop saving' => function () {
        $db = history_db();
        [$id] = Pages::save(0, history_page('v1'), 1);
        $db->exec('DROP TABLE #__revisions');
        [$same, $err] = Pages::save($id, history_page('v2'), 1);
        same([], $err);
        same($id, $same);
        same('<p>v2</p>', Pages::find($id)['translations']['pl']['body']);
    },

    'history: workflow on SQLite' => function () {
        $db = memory_db();
        (new Migrator($db))->migrate();
        history_workflow($db);
    },

    'history: workflow on MySQL' => function () {
        if (!($opt = mysql_options())) {
            return 'skip';
        }
        $name = 'pageyard_test_' . bin2hex(random_bytes(3));
        $server = Db::connect($opt);
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $db = Db::connect(['db' => $name] + $opt);
            (new Migrator($db))->migrate();
            history_workflow($db);
        } finally {
            $server->exec("DROP DATABASE `$name`");
        }
    },
];
