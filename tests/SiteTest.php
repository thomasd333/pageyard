<?php

use Pageyard\Blocks;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Database\Migrator;
use Pageyard\Home;
use Pageyard\Pages;
use Pageyard\Settings;
use Pageyard\Site\Content;
use Pageyard\Site\Html;
use Pageyard\Site\Layout;
use Pageyard\Site\Router;
use Pageyard\Site\Search;

defined('PAGEYARD') or exit;

/** Site under /cms/, languages pl (default) + en. */
function site_config(array $extra = []): void
{
    Config::set($extra + ['language' => 'pl', 'languages' => 'pl,en', 'timezone' => 'Europe/Warsaw', 'live_site' => 'http://example.com/cms', 'sef_rewrite' => true]);
    date_default_timezone_set('Europe/Warsaw');
    Router::reset();
}

/**
 * Category "aktualnosci"/"news" (pl+en) with pages a (pl+en), b (pl only, future), c (pl, unpublished);
 * page "o-nas"/"about-us" (pl+en) without category. The home page is the category.
 * @return array<string, int> ids
 */
function site_fixture(): array
{
    $db = memory_db();
    (new Migrator($db))->migrate();
    Db::setInstance($db);
    Settings::reset();
    site_config();

    [$news] = Categories::save(0, ['state' => 1, 'params' => ['layout' => 'blog', 'intro_count' => 2, 'link_count' => 1],
        'translations' => ['pl' => ['title' => 'Aktualności'], 'en' => ['title' => 'News']]]);
    [$a] = Pages::save(0, page_data(['pl' => ['title' => 'Pierwszy wpis', 'intro' => '<p>Wstęp</p>'], 'en' => ['title' => 'First post']],
        ['category_id' => $news, 'publish_up' => '2026-01-02T10:00']), 1);
    [$b] = Pages::save(0, page_data(['pl' => ['title' => 'Przyszły']], ['category_id' => $news, 'publish_up' => '2099-01-01T00:00']), 1);
    [$c] = Pages::save(0, page_data(['pl' => ['title' => 'Ukryty']], ['category_id' => $news, 'state' => 0]), 1);
    [$about] = Pages::save(0, page_data(['pl' => ['title' => 'O nas'], 'en' => ['title' => 'About us']], ['publish_up' => '2026-01-01T00:00']), 1);
    same(true, Home::set('category', $news));
    Router::reset();
    return compact('news', 'a', 'b', 'c', 'about');
}

/** [status, type, id] or [301, redirect] of a resolved path. */
function resolved(string $path): array
{
    $r = Router::resolve($path);
    return $r['status'] === 301 ? [301, $r['redirect']] : [$r['status'], $r['type'], $r['id']];
}

/** Titles of the search results in order. */
function found(string $query, string $lang = 'pl'): array
{
    return array_column(Search::run($query, $lang), 'title');
}

return [
    'search: words, Polish letters, visibility, ranking' => function () {
        $ids = site_fixture();
        Pages::save(0, page_data(['pl' => ['title' => 'Łódź zimą', 'body' => '<p>Długi spacer <a href="plik-tajny.pdf">tu</a> po mieście.</p>'],
            'en' => ['title' => 'Winter', 'body' => '<p>Long walk</p>']], ['publish_up' => '2026-02-01T00:00']), 1);
        Pages::save(0, page_data(['pl' => ['title' => 'Inny tekst', 'body' => '<p>Tu jest mowa o łodzi i o tym, że <b>zimą</b> jest ślisko.</p><p>{script tajne/skrypt x=y}</p>']],
            ['publish_up' => '2026-03-01T00:00']), 1);
        Router::reset();

        same(['Łódź zimą'], found('ŁÓDŹ'), 'Polish capitals');
        same(['Łódź zimą'], found('łódź'));
        same(['Łódź zimą', 'Inny tekst'], found('zimą'), 'a match in the title first');
        same(['Inny tekst'], found('ŚLISKO zimą'), 'all the words');
        same([], found('zimą nieistnieje'), 'not every word');
        same([], found('plik-tajny'), 'inside a tag');
        same([], found('skrypt'), 'inside a script tag');
        same([], found('a'), 'too short');
        same([], found(''));
        same(['Winter'], found('long', 'en'), 'the language');
        same([], found('long'));
        same([], found('Przyszły'), 'scheduled');
        same([], found('Ukryty'), 'unpublished');
        same(['Aktualności'], found('aktualności'), 'categories too');
        Home::set('page', $ids['about']);
        Categories::setState([$ids['news']], 0);
        Router::reset();
        same([], found('aktualności'));
        same([], found('Pierwszy'), 'a page of an unpublished category');
        same([], found('100%_'), 'LIKE characters are plain text');
    },

    'search: results link to the page, snippets are escaped and marked' => function () {
        site_fixture();
        Pages::save(0, page_data(['pl' => ['title' => 'Tytuł', 'body' => '<p>' . str_repeat('początek ', 40) . 'Szukane &lt;b&gt;słowo</p><p>' . str_repeat('koniec ', 60) . '</p>']],
            ['publish_up' => '2026-02-01T00:00']), 1);
        Router::reset();
        $result = Search::run('szukane', 'pl')[0];
        same('/cms/tytul', $result['url']);
        check(str_contains($result['snippet'], '<mark>Szukane</mark> &lt;b&gt;słowo'), $result['snippet']);
        check(str_starts_with($result['snippet'], '…') && str_ends_with($result['snippet'], '…'));
        check(mb_strlen($result['snippet']) < 260);

        $form = Search::form('en', '"><script>');
        check(str_contains($form, 'action="/cms/en/search"'));
        check(!str_contains($form, '<script>'));
    },

    'search: the address is reserved, and a search block has no text' => function () {
        site_fixture();
        [, $errors] = Pages::save(0, page_data(['pl' => ['title' => 'Search']], ['publish_up' => '2026-02-01T00:00']), 1);
        same([], $errors);
        check(Db::get()->fetchValue("SELECT alias FROM #__page_translations WHERE title = 'Search'") === 'search-2');
        [$id, $errors] = Blocks::save(0, ['title' => 'Szukaj', 'type' => 'search', 'position' => 'top', 'menu_id' => 0, 'state' => 1, 'show_title' => 0,
            'assignment' => 'all', 'targets' => [], 'css_class' => '', 'translations' => []]);
        same([], $errors);
        same(null, Blocks::revision($id));
        $layout = new Layout(null, 'pl');
        check(str_contains($layout->render('top'), 'action="/cms/search"'));
    },

    'html: relative URLs get the site root' => function () {
        same('<img src="/cms/images/a.jpg"><a href="/cms/o-nas">x</a>',
            Html::resolveUrls('<img src="images/a.jpg"><a href="o-nas">x</a>', '/cms/'));
        $kept = '<a href="https://x.pl">a</a><a href="/abs">b</a><a href="#top">c</a><a href="mailto:a@b.c">d</a>'
            . '<img src="data:image/png;base64,AA"><a href="?page=2">e</a><a href="">f</a><a href="//cdn.x/y">g</a>';
        same($kept, Html::resolveUrls($kept, '/cms/'));
        same("<a href='/x'>s</a>", Html::resolveUrls("<a href='x'>s</a>", '/'), 'single quotes');
    },

    'router: building URLs' => function () {
        site_config();
        same('/cms/', Router::url('', 'pl'));
        same('/cms/en/', Router::url('', 'en'));
        same('/cms/o-nas', Router::url('o-nas', 'pl'));
        same('/cms/en/about?page=2', Router::url('about', 'en', ['page' => 2]));
        site_config(['sef_rewrite' => false]);
        same('/cms/', Router::url('', 'pl'));
        same('/cms/index.php/o-nas', Router::url('o-nas', 'pl'));
        same('/cms/index.php/en/', Router::url('', 'en'));
    },

    'router: request path' => function () {
        site_config();
        $paths = ['/cms/' => '', '/cms' => '', '/cms/o-nas?x=1' => 'o-nas', '/cms/index.php' => '', '/cms/index.php/en/about' => 'en/about',
            '/cms/a%20b/' => 'a b/'];
        foreach ($paths as $uri => $expected) {
            $_SERVER['REQUEST_URI'] = $uri;
            same($expected, Router::requestPath(), $uri);
        }
    },

    'router: resolving content URLs' => function () {
        $ids = site_fixture();
        $r = Router::resolve('');
        same([200, 'category', $ids['news'], true, 'pl'], [$r['status'], $r['type'], $r['id'], $r['home'], $r['lang']]);
        $r = Router::resolve('en/');
        same([200, 'category', $ids['news'], 'en'], [$r['status'], $r['type'], $r['id'], $r['lang']]);
        same(200, Router::resolve('en')['status'], 'language root without the slash');

        same([200, 'page', $ids['about']], resolved('o-nas'));
        same([200, 'page', $ids['about']], resolved('en/about-us'));
        same([200, 'page', $ids['a']], resolved('aktualnosci/pierwszy-wpis'));
        same([200, 'page', $ids['a']], resolved('en/news/first-post'));
        same([200, 'page', $ids['c']], resolved('aktualnosci/ukryty'), 'visibility is checked by App');

        // One canonical URL per thing.
        same([301, '/cms/'], resolved('aktualnosci'), 'the home category under its own address');
        same([301, '/cms/en/'], resolved('en/news'));
        same([301, '/cms/aktualnosci/pierwszy-wpis'], resolved('pierwszy-wpis'), 'page of a category without its prefix');
        same([301, '/cms/o-nas'], resolved('aktualnosci/o-nas'), 'page outside the category');
        same([301, '/cms/o-nas'], resolved('o-nas/'));
        same([301, '/cms/o-nas'], resolved('pl/o-nas'), 'no prefix for the default language');

        same(404, resolved('about-us')[0], 'English alias in Polish');
        same(404, resolved('en/o-nas')[0]);
        same(404, resolved('nie-ma')[0]);
        same(404, resolved('nie-ma/pierwszy-wpis')[0]);
        same(404, resolved('o-nas/x/y')[0]);

        // Another home page; unpublished categories hide their URLs.
        Home::set('page', $ids['about']);
        Router::reset();
        same([200, 'page', $ids['about']], resolved(''));
        same([301, '/cms/'], resolved('o-nas'));
        same([200, 'category', $ids['news']], resolved('aktualnosci'));
        Categories::setState([$ids['news']], 0);
        Router::reset();
        same(404, resolved('aktualnosci')[0]);
        same(404, resolved('aktualnosci/pierwszy-wpis')[0]);
    },

    'router: page and category URLs' => function () {
        $ids = site_fixture();
        same('/cms/o-nas', Router::pageUrl($ids['about'], null, 'o-nas', 'pl'));
        same('/cms/en/about-us', Router::pageUrl($ids['about'], null, 'about-us', 'en'));
        same('/cms/aktualnosci/pierwszy-wpis', Router::pageUrl($ids['a'], $ids['news'], 'pierwszy-wpis', 'pl'));
        same('/cms/en/news/first-post', Router::pageUrl($ids['a'], $ids['news'], 'first-post', 'en'));
        same('/cms/', Router::categoryUrl($ids['news'], 'pl'), 'home category');

        [$solo] = Categories::save(0, ['state' => 1, 'translations' => ['pl' => ['title' => 'Tylko PL']]]);
        [$p] = Pages::save(0, page_data(['pl' => ['title' => 'X'], 'en' => ['title' => 'Y']], ['category_id' => $solo]), 1);
        Router::reset();
        same('/cms/tylko-pl', Router::categoryUrl($solo, 'pl'));
        same(null, Router::categoryUrl($solo, 'en'));
        same(null, Router::pageUrl($p, $solo, 'y', 'en'), 'category without an English translation');
    },

    'aliases: pages and categories share the first segment' => function () {
        $ids = site_fixture();
        [$cat, , $renamed] = Categories::save(0, ['state' => 1, 'translations' => ['pl' => ['title' => 'O nas']]]);
        same(['o-nas-2', ['pl']], [Categories::find($cat)['translations']['pl']['alias'], $renamed], 'taken by a page');
        [$page, , $renamed] = Pages::save(0, page_data(['pl' => ['title' => 'Aktualności']]), 1);
        same(['aktualnosci-2', ['pl']], [Pages::find($page)['translations']['pl']['alias'], $renamed], 'taken by a category');
        [$page] = Pages::save(0, page_data(['pl' => ['title' => 'Images', 'alias' => 'images'], 'en' => ['title' => 'En', 'alias' => 'pl']]), 1);
        $tr = Pages::find($page)['translations'];
        same(['images-2', 'pl-2'], [$tr['pl']['alias'], $tr['en']['alias']], 'system folders and language codes');
        same('aktualnosci', Categories::find($ids['news'])['translations']['pl']['alias'], 'existing aliases are kept on save');
    },

    'home: set, protected and remembered' => function () {
        $ids = site_fixture();
        same(['type' => 'category', 'id' => $ids['news']], Home::get());
        same(0, Categories::setState([$ids['news']], 0), 'the home category stays published');
        same([0, []], Categories::delete([$ids['news']], 'pl'), 'and is not deleted');
        same(false, Home::set('page', 999));
        same(false, Home::set('menu', $ids['about']));

        Pages::setState([$ids['c']], 0, 1);
        same(true, Home::set('page', $ids['c']));
        same(1, (int) Pages::find($ids['c'])['state'], 'setting a home page publishes it');
        same(0, Pages::delete([$ids['c']]));
        same(1, Pages::delete([$ids['b']]));
        Settings::set('home', ['type' => 'page', 'id' => $ids['b']]);
        same(null, Home::get(), 'a deleted target means no home page');
    },

    'content: visibility rules' => function () {
        $ids = site_fixture();
        check(Content::page($ids['a'], 'pl') !== null);
        same(null, Content::page($ids['b'], 'pl'), 'future publish date');
        same(null, Content::page($ids['c'], 'pl'), 'unpublished');
        same(null, Content::page($ids['c'], 'en'), 'no translation');
        $langs = Content::pageLanguages($ids['a']);
        ksort($langs);
        same(['en' => 'first-post', 'pl' => 'pierwszy-wpis'], $langs);
        $list = Content::categoryPages($ids['news'], 'pl', 'date_desc', 10, 0);
        same([1, [$ids['a']]], [$list['total'], array_column($list['items'], 'id')]);
        same('Pierwszy wpis', Content::pageLink($ids['a'], 'pl')['title']);
        Home::set('page', $ids['about']);
        Categories::setState([$ids['news']], 0);
        same(null, Content::page($ids['a'], 'pl'), 'unpublished category');
        same(null, Content::category($ids['news'], 'pl'));
        check(Content::page($ids['about'], 'pl') !== null, 'pages without a category are unaffected');
    },

    'categories: list options' => function () {
        $ids = site_fixture();
        same(['blog', 2, 1, 1], (function () use ($ids) {
            $p = Categories::find($ids['news'])['params'];
            return [$p['layout'], $p['intro_count'], $p['link_count'], $p['columns']];
        })());
        [, $err] = Categories::save(0, ['state' => 1, 'params' => ['layout' => 'blog', 'intro_count' => 0, 'link_count' => 0, 'columns' => 9],
            'translations' => ['pl' => ['title' => '']]]);
        same(['pl.title', 'columns', 'intro_count'], array_keys($err));
        [$id, $err] = Categories::save(0, ['state' => 1, 'params' => ['layout' => 'list', 'per_page' => '15', 'order' => 'evil'],
            'translations' => ['pl' => ['title' => 'Lista', 'meta_description' => 'Opis']]]);
        same([], $err);
        $c = Categories::find($id);
        same(['list', 15, 'date_desc', false, 'Opis'], [$c['params']['layout'], $c['params']['per_page'], $c['params']['order'], $c['params']['show_date'], $c['translations']['pl']['meta_description']]);
    },

    'migration 0006: routes become content URLs' => function () {
        // Database as it was after 0005, with routes, menu items and blocks.
        $db = memory_db();
        $old = sys_get_temp_dir() . '/pageyard-mig-' . bin2hex(random_bytes(4));
        mkdir($old);
        foreach (glob(PY_ROOT . '/core/Database/migrations/000[1-5]_*.php') as $file) {
            copy($file, $old . '/' . basename($file));
        }
        (new Migrator($db, $old))->migrate();
        remove_dir($old);
        $now = Db::now();
        $db->insert('#__categories', ['id' => 1, 'state' => 1, 'created' => $now, 'modified' => $now]);
        $db->insert('#__category_translations', ['category_id' => 1, 'lang' => 'pl', 'title' => 'Blog', 'alias' => 'o-nas']);
        $db->insert('#__pages', ['id' => 1, 'category_id' => null, 'state' => 1, 'publish_up' => $now, 'created' => $now, 'modified' => $now]);
        $db->insert('#__page_translations', ['page_id' => 1, 'lang' => 'pl', 'title' => 'O nas', 'alias' => 'o-nas']);
        $db->insert('#__routes', ['id' => 1, 'type' => 'list', 'state' => 1, 'home' => 0, 'category_id' => 1,
            'params' => '{"layout":"list","per_page":7}', 'created' => $now, 'modified' => $now]);
        $db->insert('#__route_translations', ['route_id' => 1, 'lang' => 'pl', 'title' => 'Lista artykułów', 'path' => 'lista', 'meta_description' => 'Meta']);
        $db->insert('#__routes', ['id' => 2, 'type' => 'page', 'state' => 1, 'home' => 1, 'page_id' => 1, 'created' => $now, 'modified' => $now]);
        $db->insert('#__route_translations', ['route_id' => 2, 'lang' => 'pl', 'title' => 'O nas', 'path' => null]);
        $db->insert('#__routes', ['id' => 3, 'type' => 'external', 'state' => 1, 'home' => 0, 'params' => '{"url":"https://x.pl","new_window":true}', 'created' => $now, 'modified' => $now]);
        $db->insert('#__route_translations', ['route_id' => 3, 'lang' => 'pl', 'title' => 'X', 'path' => null]);
        $db->insert('#__menus', ['id' => 1, 'title' => 'Main', 'created' => $now, 'modified' => $now]);
        foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 99] as $item => $route) {
            $db->insert('#__menu_items', ['id' => $item, 'menu_id' => 1, 'parent_id' => 0, 'route_id' => $route, 'state' => 1, 'ordering' => $item, 'created' => $now, 'modified' => $now]);
        }
        $db->insert('#__menu_item_translations', ['item_id' => 2, 'lang' => 'pl', 'label' => 'Start']);
        $db->insert('#__blocks', ['id' => 5, 'title' => 'B', 'type' => 'html', 'position' => 'left', 'state' => 1, 'ordering' => 1,
            'assignment' => 'only', 'routes' => '[1,2,3]', 'created' => $now, 'modified' => $now]);

        same(['0006_content_urls'], array_slice((new Migrator($db))->migrate(), 0, 1));
        Db::setInstance($db);
        Settings::reset();
        same(false, $db->tableExists('#__routes'));
        same(['type' => 'page', 'id' => 1], Home::get());
        $c = Categories::find(1);
        same(['list', 7, 'Meta', 'o-nas-2'], [$c['params']['layout'], $c['params']['per_page'], $c['translations']['pl']['meta_description'], $c['translations']['pl']['alias']]);
        $items = $db->fetchAll('SELECT id, type, page_id, category_id, url, new_window FROM #__menu_items ORDER BY id');
        same([[1, 'category', 1], [2, 'home', null], [3, 'url', null]], array_map(fn ($i) => [(int) $i['id'], $i['type'], $i['category_id'] !== null ? (int) $i['category_id'] : null], $items));
        same(['https://x.pl', 1], [$items[2]['url'], (int) $items[2]['new_window']]);
        $labels = $db->fetchAll('SELECT item_id, label FROM #__menu_item_translations ORDER BY item_id');
        same([[1, 'Lista artykułów'], [2, 'Start'], [3, 'X']], array_map(fn ($l) => [(int) $l['item_id'], $l['label']], $labels));
        same('["category:1","home"]', $db->fetchValue('SELECT targets FROM #__blocks WHERE id = 5'));
    },
];
