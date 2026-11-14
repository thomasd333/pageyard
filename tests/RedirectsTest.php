<?php

use Pageyard\Categories;
use Pageyard\Database\Db;
use Pageyard\Pages;
use Pageyard\Redirects;
use Pageyard\Site\Router;

defined('PAGEYARD') or exit;

/** Saves a page with changed texts, keeping the rest of it. */
function rename_page(int $id, array $translations, ?int $category = null): void
{
    [, $errors] = Pages::save($id, page_data($translations, ['category_id' => $category, 'publish_up' => '2026-01-01T00:00']), 1);
    same([], $errors);
    Router::reset();
}

function manual_redirect(string $from, string $type, int $targetId = 0, string $url = '', int $id = 0): array
{
    $result = Redirects::save($id, ['from' => $from, 'target_type' => $type, 'target_id' => $targetId, 'url' => $url]);
    Router::reset();
    return $result;
}

return [
    'redirects: a renamed page keeps its old address, without chains' => function () {
        $ids = site_fixture();
        rename_page($ids['about'], ['pl' => ['title' => 'O nas', 'alias' => 'o-firmie'], 'en' => ['title' => 'About us']]);
        same([301, '/cms/o-firmie'], resolved('o-nas'));
        same([404, null, null], resolved('o-nasx'));
        same([200, 'page', $ids['about']], resolved('o-firmie'));
        same([200, 'page', $ids['about']], resolved('en/about-us'), 'other language untouched');

        rename_page($ids['about'], ['pl' => ['title' => 'O nas', 'alias' => 'kontakt'], 'en' => ['title' => 'About us']]);
        same([301, '/cms/kontakt'], resolved('o-nas'), 'first address goes straight to the newest one');
        same([301, '/cms/kontakt'], resolved('o-firmie'));

        // Back to an old alias: the page answers it and its row is gone.
        rename_page($ids['about'], ['pl' => ['title' => 'O nas', 'alias' => 'o-nas'], 'en' => ['title' => 'About us']]);
        same([200, 'page', $ids['about']], resolved('o-nas'));
        same(null, Redirects::find('pl', 'o-nas'));
        same([301, '/cms/o-nas'], resolved('kontakt'));
    },

    'redirects: a page moved out of a deleted category, and hits' => function () {
        $ids = site_fixture();
        [$other] = Categories::save(0, ['state' => 1, 'translations' => ['pl' => ['title' => 'Inna']]]);
        Router::reset();
        rename_page($ids['a'], ['pl' => ['title' => 'Pierwszy wpis'], 'en' => ['title' => 'First post']], $other);
        same(0, Categories::delete([$other], 'pl')[0], 'has a page, so it is kept');
        rename_page($ids['a'], ['pl' => ['title' => 'Pierwszy wpis'], 'en' => ['title' => 'First post']]);
        same(1, Categories::delete([$other], 'pl')[0]);
        Router::reset();
        same([301, '/cms/pierwszy-wpis'], resolved('inna/pierwszy-wpis'), 'deleted category, row of the page');
        same(1, (int) Redirects::find('pl', 'inna/pierwszy-wpis')['hits']);
        check(Redirects::find('pl', 'inna/pierwszy-wpis')['last_hit'] !== null);
        same(1, (int) Redirects::find('pl', 'inna/pierwszy-wpis')['auto']);
    },

    'redirects: a renamed category takes its pages along' => function () {
        $ids = site_fixture();
        [$cat] = Categories::save(0, ['state' => 1, 'params' => [], 'translations' => ['pl' => ['title' => 'Stara']]]);
        Router::reset();
        rename_page($ids['about'], ['pl' => ['title' => 'Wpis'], 'en' => ['title' => 'About us']], $cat);
        same([], Categories::save($cat, ['state' => 1, 'params' => [], 'translations' => ['pl' => ['title' => 'Nowa', 'alias' => 'nowa']]])[1]);
        Router::reset();
        same([301, '/cms/nowa'], resolved('stara'));
        same([301, '/cms/nowa/wpis'], resolved('stara/wpis'));
        same([200, 'page', $ids['about']], resolved('nowa/wpis'));

        // Then the page is renamed: the old category + old alias still lead to it.
        rename_page($ids['about'], ['pl' => ['title' => 'Wpis', 'alias' => 'artykul'], 'en' => ['title' => 'About us']], $cat);
        same([301, '/cms/nowa/artykul'], resolved('nowa/wpis'));
        same([301, '/cms/nowa/artykul'], resolved('stara/wpis'));
        same([404, null, null], resolved('stara/nic'));
    },

    'redirects: nothing leads to what visitors can\'t see; a deleted page works again when restored' => function () {
        $ids = site_fixture();
        rename_page($ids['about'], ['pl' => ['title' => 'O nas', 'alias' => 'inaczej'], 'en' => ['title' => 'About us']]);
        same([301, '/cms/inaczej'], resolved('o-nas'));
        Pages::setState([$ids['about']], 0, 1);
        same([404, null, null], resolved('o-nas'), 'unpublished');
        Pages::setState([$ids['about']], 1, 1);
        same(1, Pages::delete([$ids['about']]));
        same([404, null, null], resolved('o-nas'), 'deleted');
        check(Redirects::find('pl', 'o-nas') !== null, 'the row stays for a restore');
    },

    'redirects: new content on an old address wins and removes the row' => function () {
        $ids = site_fixture();
        rename_page($ids['about'], ['pl' => ['title' => 'O nas', 'alias' => 'inaczej'], 'en' => ['title' => 'About us']]);
        [$new] = Pages::save(0, page_data(['pl' => ['title' => 'O nas']], ['publish_up' => '2026-01-01T00:00']), 1);
        Router::reset();
        same([200, 'page', $new], resolved('o-nas'));
        same(null, Redirects::find('pl', 'o-nas'));
    },

    'redirects: made by hand, validation' => function () {
        $ids = site_fixture();
        [$id, $errors] = manual_redirect('http://example.com/cms/index.php/stara/strona.html/?x=1#y', 'page', $ids['about']);
        same([], $errors);
        check(Redirects::find('pl', 'stara/strona.html') !== null, 'pasted address is cleaned');
        same(0, (int) Redirects::get($id)['auto']);
        same([301, '/cms/o-nas'], resolved('stara/strona.html'));
        same([301, '/cms/en/about-us'], (function () use ($ids) {
            same([], manual_redirect('/en/old', 'page', $ids['about'])[1]);
            return resolved('en/old');
        })(), 'language prefix');
        check(Redirects::find('en', 'old') !== null);

        same([], manual_redirect('/ext', 'url', 0, 'https://example.org/a?b=1')[1]);
        same([301, 'https://example.org/a?b=1'], resolved('ext'));
        same([], manual_redirect('/int', 'url', 0, '/o-nas?x=1')[1]);
        same([301, '/cms/o-nas?x=1'], resolved('int'));
        same([], manual_redirect('/to-home', 'url', 0, '/')[1]);
        same([301, '/cms/'], resolved('to-home'));
        same([], manual_redirect('/polskie', 'url', 0, '/zażółć gęślą')[1]);
        same([301, '/cms/za%C5%BC%C3%B3%C5%82%C4%87%20g%C4%99%C5%9Bl%C4%85'], resolved('polskie'));

        check(isset(manual_redirect('', 'url', 0, '/x')[1]['from']), 'empty');
        check(isset(manual_redirect('/', 'url', 0, '/x')[1]['from']), 'home');
        check(isset(manual_redirect('/o-nas', 'url', 0, '/x')[1]['from']), 'live page');
        check(isset(manual_redirect('/aktualnosci/pierwszy-wpis', 'url', 0, '/x')[1]['from']), 'live page in a category');
        check(isset(manual_redirect('/administrator/x', 'url', 0, '/x')[1]['from']), 'system folder');
        check(isset(manual_redirect('/sitemap.xml', 'url', 0, '/x')[1]['from']), 'sitemap');
        check(isset(manual_redirect('/ext', 'url', 0, '/x')[1]['from']), 'duplicate');
        check(!isset(manual_redirect('/ext', 'url', 0, 'https://example.org/z', Redirects::find('pl', 'ext')['id'])[1]['from']), 'its own row');
        foreach (['', 'javascript:alert(1)', '//evil.example', 'mailto:a@b.c', "https://a.b/\r\nX: y", 'https://'] as $bad) {
            check(isset(manual_redirect('/nowy', 'url', 0, $bad)[1]['url']), "target: $bad");
        }
        check(isset(manual_redirect('/nowy', 'page', 99999)[1]['page_id']), 'missing page');
        check(isset(manual_redirect('/nowy', 'category', 0)[1]['category_id']), 'missing category');
        same(null, Redirects::find('pl', 'nowy'));

        same([], manual_redirect('/p1', 'url', 0, '/p2')[1]);
        same([], manual_redirect('/p2', 'url', 0, '/p3')[1]);
        check(isset(manual_redirect('/p3', 'url', 0, '/p1')[1]['url']), 'loop');
        check(isset(manual_redirect('/same', 'url', 0, '/same/')[1]['url']), 'to itself');
    },

    'redirects: list, search and delete' => function () {
        $ids = site_fixture();
        rename_page($ids['about'], ['pl' => ['title' => 'O nas', 'alias' => 'inaczej'], 'en' => ['title' => 'About us']]);
        manual_redirect('/ręczny', 'url', 0, 'https://example.org/');
        $state = ['search' => '', 'kind' => '', 'sort' => 'path', 'dir' => 'asc'];
        $list = Redirects::list($state, 10, 0);
        same(2, $list['total']);
        same('O nas', $list['items'][0]['page_title']);
        same(['o-nas'], array_column(Redirects::list(['kind' => 'auto'] + $state, 10, 0)['items'], 'path'));
        same(['ręczny'], array_column(Redirects::list(['kind' => 'manual'] + $state, 10, 0)['items'], 'path'));
        same(['ręczny'], array_column(Redirects::list(['search' => 'example.org'] + $state, 10, 0)['items'], 'path'));
        same(2, Redirects::delete(array_map('intval', array_column($list['items'], 'id'))));
        same(0, (int) Db::get()->fetchValue('SELECT COUNT(*) FROM #__redirects'));
    },
];
