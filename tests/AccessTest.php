<?php

use Pageyard\Access;
use Pageyard\Blocks;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Home;
use Pageyard\Menus;
use Pageyard\Pages;
use Pageyard\Site\Content;
use Pageyard\Site\Layout;
use Pageyard\Site\Router;
use Pageyard\Site\Sitemap;

defined('PAGEYARD') or exit;

/**
 * site_fixture() plus: page "Tajne" (logged-in users) in the news category, category "Klub" (logged-in
 * users) with an ordinary page "Klubowy".
 */
function access_fixture(array $config = []): array
{
    $ids = site_fixture();
    site_config($config);
    Access::setMember(false);
    [$secret] = Pages::save(0, page_data(['pl' => ['title' => 'Tajne', 'body' => '<p>sekret zimą</p>']],
        ['category_id' => $ids['news'], 'publish_up' => '2026-01-03T00:00', 'access' => 1]), 1);
    [$club] = Categories::save(0, ['state' => 1, 'access' => 1, 'params' => [], 'translations' => ['pl' => ['title' => 'Klub']]]);
    [$clubPage] = Pages::save(0, page_data(['pl' => ['title' => 'Klubowy', 'body' => '<p>sekret klubu</p>']],
        ['category_id' => $club, 'publish_up' => '2026-01-03T00:00']), 1);
    Router::reset();
    return $ids + compact('secret', 'club', 'clubPage');
}

return [
    'access: content for logged-in users is hidden from guests everywhere' => function () {
        $ids = access_fixture();
        $titles = fn () => array_column(Content::categoryPages($ids['news'], 'pl', 'date_desc', 10, 0)['items'], 'title');

        same(null, Content::page($ids['secret'], 'pl'));
        same(null, Content::page($ids['clubPage'], 'pl'), 'an ordinary page in a category for logged-in users');
        same(null, Content::category($ids['club'], 'pl'));
        same(null, Content::pageLink($ids['secret'], 'pl'));
        same(['Pierwszy wpis'], $titles());
        same([], found('sekret'));

        Access::setMember(true);
        same('Tajne', Content::page($ids['secret'], 'pl')['title']);
        same('Klubowy', Content::page($ids['clubPage'], 'pl')['title']);
        same(['Tajne', 'Pierwszy wpis'], $titles());
        $both = found('sekret');
        sort($both);
        same(['Klubowy', 'Tajne'], $both);

        // The sitemap is for everyone, whoever asks.
        $urls = array_merge(...array_map('array_values', array_column(Sitemap::entries(), 'urls')));
        sort($urls);
        same(['/cms/', '/cms/aktualnosci/pierwszy-wpis', '/cms/en/', '/cms/en/about-us', '/cms/en/news/first-post', '/cms/o-nas'], $urls);
        Access::setMember(false);

        same('Klubowy', Access::as(true, fn () => Content::page($ids['clubPage'], 'pl')['title']));
        same(false, Access::member(), 'as() puts it back');
    },

    'access: the home page is always for everyone' => function () {
        $ids = access_fixture();
        Home::set('page', $ids['about']);
        Pages::save($ids['about'], page_data(['pl' => ['title' => 'O nas'], 'en' => ['title' => 'About us']], ['access' => 1]), 1);
        same(0, (int) Pages::find($ids['about'])['access']);
        Home::set('category', $ids['club']);
        same(0, (int) Categories::find($ids['club'])['access'], 'becoming the home page makes it public');
        Categories::save($ids['club'], ['state' => 1, 'access' => 1, 'params' => [], 'translations' => ['pl' => ['title' => 'Klub']]]);
        same(0, (int) Categories::find($ids['club'])['access']);

        // Saves that don't mention access (moves, restores) keep it.
        Pages::moveTo([$ids['secret']], null, 1);
        same(1, (int) Pages::find($ids['secret'])['access']);
    },

    'access: list action and filter, the home page left out' => function () {
        $ids = access_fixture();
        Home::set('page', $ids['about']);
        same([1, true], Access::change('#__pages', [$ids['a'], $ids['about']], Access::MEMBERS));
        same(1, (int) Pages::find($ids['a'])['access']);
        same(0, (int) Pages::find($ids['about'])['access']);
        same([1, false], Access::change('#__pages', [$ids['a']], Access::GUESTS), 'guests only is not for pages: everyone');
        same(0, (int) Pages::find($ids['a'])['access']);
        same([1, false], Access::change('#__blocks', [Blocks::save(0, block_data())[0]], Access::GUESTS));
        throws(fn () => Access::change('#__users', [1], 0), InvalidArgumentException::class);

        $state = ['search' => '', 'state' => '', 'category' => '', 'sort' => 'title', 'dir' => 'asc'];
        same(['Tajne'], array_column(Pages::list(['access' => '1'] + $state, 'pl', 0, 0)['items'], 'title'), 'the page itself, not by its category');
        same(['Klub'], array_column(Categories::list(['access' => '1', 'search' => '', 'state' => '', 'sort' => 'title', 'dir' => 'asc'], 'pl', 0, 0)['items'], 'title'));
    },

    'access: menu items and blocks, account items' => function () {
        $ids = layout_fixture();
        site_config(['site_accounts' => true, 'registration' => true, 'mail_transport' => 'mail', 'mail_from' => 'a@example.com']);
        Access::setMember(false);
        [, $err] = Menus::saveItem($ids['itemAbout'], item_data($ids['menu'], 'page', ['page_id' => $ids['about'], 'access' => 1]));
        same([], $err);
        Menus::saveItem(0, item_data($ids['menu'], 'account', ['account' => 'login']));
        Menus::saveItem(0, item_data($ids['menu'], 'account', ['account' => 'register']));
        Menus::saveItem(0, item_data($ids['menu'], 'account', ['account' => '']));
        Menus::saveItem(0, item_data($ids['menu'], 'url', ['url' => 'https://example.org', 'labels' => ['pl' => 'Tylko goście'], 'access' => 2]));
        Blocks::save(0, block_data(['title' => 'Dla zalogowanych', 'access' => 1, 'translations' => ['pl' => ['content' => '<p>x</p>']]]));
        Blocks::save(0, block_data(['title' => 'Dla gości', 'access' => 2, 'translations' => ['pl' => ['content' => '<p>y</p>']]]));
        $labels = fn () => array_column((new Layout(null, 'pl'))->menu($ids['menu']), 'label');
        $blocks = fn () => array_column((new Layout(null, 'pl'))->blocks('left'), 'title');

        same(['Aktualności', t('ACCOUNT_LOGIN'), t('ACCOUNT_REGISTER'), 'Tylko goście'], array_slice($labels(), 1));
        same(['Dla gości'], $blocks());

        Access::setMember(true);
        same(['O nas', 'Aktualności', t('ACCOUNT_PROFILE')], array_slice($labels(), 1));
        same(['Dla zalogowanych'], $blocks());
        same('/cms/account', (new Layout(null, 'pl'))->menu($ids['menu'])[3]['url']);

        site_config(['site_accounts' => false]);
        same(['O nas', 'Aktualności'], array_slice($labels(), 1), 'account items go with the accounts');
        Access::setMember(false);
    },
];
