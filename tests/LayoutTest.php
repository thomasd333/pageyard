<?php

use Pageyard\Blocks;
use Pageyard\Categories;
use Pageyard\Menus;
use Pageyard\Pages;
use Pageyard\Settings;
use Pageyard\Site\App;
use Pageyard\Site\Layout;
use Pageyard\Site\Router;

defined('PAGEYARD') or exit;

function block_data(array $overrides = []): array
{
    return array_merge(['title' => 'Blok', 'type' => 'html', 'position' => 'left', 'menu_id' => 0, 'state' => 1,
        'show_title' => true, 'assignment' => 'all', 'targets' => [], 'css_class' => '', 'translations' => []], $overrides);
}

function item_data(int $menu, string $type, array $overrides = []): array
{
    return array_merge(['menu_id' => $menu, 'parent_id' => 0, 'type' => $type, 'page_id' => 0, 'category_id' => 0, 'url' => '',
        'new_window' => false, 'state' => 1, 'labels' => []], $overrides);
}

/** Views as Layout sees them. */
function ctx(bool $home = false, ?int $page = null, ?int $category = null): array
{
    return ['home' => $home, 'page' => $page, 'category' => $category];
}

/** site_fixture() plus a menu: Start (home), O nas (page, own label) with child Google (url), Aktualności (category). */
function layout_fixture(): array
{
    $ids = site_fixture();
    [$menu] = Menus::save(0, 'Główne');
    [$itemStart] = Menus::saveItem(0, item_data($menu, 'home'));
    [$itemAbout] = Menus::saveItem(0, item_data($menu, 'page', ['page_id' => $ids['about'], 'labels' => ['pl' => 'Kim jesteśmy']]));
    [$itemGoogle, $err] = Menus::saveItem(0, item_data($menu, 'url', ['parent_id' => $itemAbout, 'url' => 'https://google.com', 'new_window' => true, 'labels' => ['pl' => 'Google']]));
    same([], $err);
    [$itemNews] = Menus::saveItem(0, item_data($menu, 'category', ['category_id' => $ids['news']]));
    Router::reset();
    return $ids + compact('menu', 'itemStart', 'itemAbout', 'itemGoogle', 'itemNews');
}

return [
    'template: code for the head and body, not in previews or offline' => function () {
        site_fixture();
        Settings::set('code_head', '<meta name="x" content="y">');
        Settings::set('code_body', '<script>go()</script>');
        $doc = new Pageyard\Site\Document();
        same('<meta name="x" content="y">', $doc->code('head'));
        same('<script>go()</script>', $doc->code('body'), 'as typed');
        $doc->status = 404;
        same('<script>go()</script>', $doc->code('body'));
        $doc->status = 503;
        same('', $doc->code('head'), 'offline');
        $doc->status = 200;
        $doc->preview = [];
        same('', $doc->code('head'), 'preview');
        Settings::reset();
    },

    'template: files in templates/custom/ replace the originals' => function () {
        $root = temp_root();
        mkdir("$root/templates/site/html", 0777, true);
        mkdir("$root/templates/custom/html", 0777, true);
        mkdir("$root/templates/custom/css", 0777, true);
        file_put_contents("$root/templates/site/index.php", 'site');
        file_put_contents("$root/templates/site/html/blog.php", 'site');
        file_put_contents("$root/templates/site/html/page.php", 'site');
        file_put_contents("$root/templates/custom/README.md", 'readme');
        same("$root/templates/site/html/blog.php", App::templateFile('html/blog.php', $root));
        same(null, App::customStylesheet($root));
        same([], App::customFiles($root), 'the README does not count');

        file_put_contents("$root/templates/custom/html/blog.php", 'custom');
        file_put_contents("$root/templates/custom/html/page.php", 'custom');
        file_put_contents("$root/templates/custom/css/custom.css", 'a{}');
        touch("$root/templates/custom/html/page.php", time() - 100);
        same("$root/templates/custom/html/blog.php", App::templateFile('html/blog.php', $root));
        same("$root/templates/site/index.php", App::templateFile('index.php', $root));
        check(str_contains((string) App::customStylesheet($root), 'templates/custom/css/custom.css?v='));
        same([
            ['path' => 'css/custom.css', 'replaces' => false, 'newer' => false],
            ['path' => 'html/blog.php', 'replaces' => true, 'newer' => false],
            ['path' => 'html/page.php', 'replaces' => true, 'newer' => true],
        ], App::customFiles($root));
    },

    'menus: items, order, parents and labels' => function () {
        $ids = layout_fixture();
        $items = Menus::items($ids['menu'], 'pl');
        same([$ids['itemStart'], $ids['itemAbout'], $ids['itemGoogle'], $ids['itemNews']], array_map(fn ($i) => (int) $i['id'], $items));
        same([0, 0, 1, 0], array_column($items, 'level'));
        same(['Aktualności', 'O nas', 'https://google.com', 'Aktualności'], array_column($items, 'target_title'), 'home = the home category');
        same('Kim jesteśmy', $items[1]['label']);
        same([['en', 'pl'], ['pl']], [$items[1]['languages'], $items[2]['languages']]);

        same(true, Menus::moveItem($ids['itemAbout'], -1));
        same(false, Menus::moveItem($ids['itemAbout'], -1), 'already first');
        same([$ids['itemAbout'], $ids['itemGoogle'], $ids['itemStart'], $ids['itemNews']], array_map(fn ($i) => (int) $i['id'], Menus::items($ids['menu'], 'pl')));

        // Validation: targets, labels of addresses, parents.
        [, $err] = Menus::saveItem(0, item_data($ids['menu'], 'page', ['page_id' => 999]));
        same(['page_id'], array_keys($err));
        [, $err] = Menus::saveItem(0, item_data($ids['menu'], 'category'));
        same(['category_id'], array_keys($err));
        [, $err] = Menus::saveItem(0, item_data($ids['menu'], 'url', ['url' => 'javascript:alert(1)']));
        same(['url', 'label_pl'], array_keys($err));
        [, $err] = Menus::saveItem(0, item_data($ids['menu'], 'home', ['parent_id' => $ids['itemGoogle']]));
        same(['parent_id'], array_keys($err), 'a child can\'t get children');
        [, $err] = Menus::saveItem($ids['itemAbout'], item_data($ids['menu'], 'page', ['page_id' => $ids['about'], 'parent_id' => $ids['itemStart']]));
        same(['parent_id'], array_keys($err), 'a parent with children can\'t become a child');
        [$other] = Menus::save(0, 'Stopka');
        [, $err] = Menus::saveItem(0, item_data($other, 'home', ['parent_id' => $ids['itemAbout']]));
        same(['parent_id'], array_keys($err), 'parent from another menu');

        // Deleting a parent moves its children up; deleting a page or category deletes its items.
        same(1, Menus::deleteItems([$ids['itemAbout']]));
        same(0, (int) Menus::findItem($ids['itemGoogle'])['parent_id']);
        [$item] = Menus::saveItem(0, item_data($ids['menu'], 'page', ['page_id' => $ids['b']]));
        Pages::delete([$ids['b']]);
        same(null, Menus::findItem($item));
        [$empty] = Categories::save(0, ['state' => 1, 'translations' => ['pl' => ['title' => 'Pusta']]]);
        [$item] = Menus::saveItem(0, item_data($ids['menu'], 'category', ['category_id' => $empty]));
        Categories::delete([$empty], 'pl');
        same(null, Menus::findItem($item));
    },

    'menus: a menu used by a block is not deleted' => function () {
        $ids = layout_fixture();
        Blocks::save(0, block_data(['type' => 'menu', 'menu_id' => $ids['menu'], 'position' => 'top']));
        same([0, ['Główne']], Menus::delete([$ids['menu']]));
        [$empty] = Menus::save(0, 'Pusty');
        same([1, []], Menus::delete([$empty]));
    },

    'layout: menu tree for the current view and language' => function () {
        $ids = layout_fixture();
        $tree = (new Layout(ctx(page: $ids['about']), 'pl'))->menu($ids['menu']);
        same(['Aktualności', 'Kim jesteśmy', 'Aktualności'], array_column($tree, 'label'));
        same([false, true, false], array_column($tree, 'active'));
        same(['/cms/', '/cms/o-nas', '/cms/'], array_column($tree, 'url'));
        same([['label' => 'Google', 'url' => 'https://google.com', 'external' => true, 'new_window' => true, 'active' => false, 'children' => []]],
            $tree[1]['children']);

        // A page in the category marks the category item; the home view marks the home item.
        same([false, false, true], array_column((new Layout(ctx(page: $ids['a'], category: $ids['news']), 'pl'))->menu($ids['menu']), 'active'));
        same([true, false, true], array_column((new Layout(ctx(true, null, $ids['news']), 'pl'))->menu($ids['menu']), 'active'));

        // English: the address has no English label, so it disappears; labels fall back to titles.
        $en = (new Layout(null, 'en'))->menu($ids['menu']);
        same(['News', 'About us', 'News'], array_column($en, 'label'));
        same(['/cms/en/', '/cms/en/about-us', '/cms/en/'], array_column($en, 'url'));
        same([], $en[1]['children']);

        // Unpublished items and invisible targets are hidden (with their children).
        Menus::setItemState([$ids['itemStart']], 0);
        Pages::setState([$ids['about']], 0, 1);
        same(['Aktualności'], array_column((new Layout(null, 'pl'))->menu($ids['menu']), 'label'));
    },

    'blocks: validation, assignment, language and ordering' => function () {
        $ids = layout_fixture();
        Settings::reset();
        [, $err] = Blocks::save(0, block_data(['title' => '', 'type' => 'menu', 'assignment' => 'only', 'targets' => ['route:1'], 'css_class' => 'x;y']));
        same(['title', 'menu_id', 'targets', 'css_class'], array_keys($err));

        [$html] = Blocks::save(0, block_data(['title' => 'Kontakt', 'translations' => ['pl' => ['title' => 'Kontakt', 'content' => '<p><img src="images/a.png"></p>']]]));
        [$only] = Blocks::save(0, block_data(['title' => 'Tylko o nas', 'assignment' => 'only', 'targets' => ['page:' . $ids['about']],
            'translations' => ['pl' => ['content' => '<p>O</p>'], 'en' => ['content' => '<p>A</p>']]]));
        [$except] = Blocks::save(0, block_data(['title' => 'Poza o nas', 'position' => 'right', 'assignment' => 'except', 'targets' => ['page:' . $ids['about']],
            'translations' => ['pl' => ['content' => '<p>E</p>']]]));
        [$news] = Blocks::save(0, block_data(['title' => 'Blog', 'position' => 'footer', 'assignment' => 'only', 'targets' => ['category:' . $ids['news']],
            'translations' => ['pl' => ['content' => '<p>N</p>']]]));
        [$home] = Blocks::save(0, block_data(['title' => 'Start', 'position' => 'footer', 'assignment' => 'only', 'targets' => ['home'],
            'translations' => ['pl' => ['content' => '<p>H</p>']]]));
        [$menuBlock] = Blocks::save(0, block_data(['title' => 'Menu', 'type' => 'menu', 'menu_id' => $ids['menu'], 'position' => 'top']));
        $blockIds = fn (Layout $l, string $pos) => array_map(fn ($b) => (int) $b['id'], $l->blocks($pos));

        $onAbout = new Layout(ctx(page: $ids['about']), 'pl');
        same([$html, $only], $blockIds($onAbout, 'left'));
        same(false, $onAbout->has('right'));
        same(true, $onAbout->has('top'));
        same(false, $onAbout->has('footer'));
        same('Kontakt', $onAbout->blocks('left')[0]['heading']);
        same('<p><img src="/cms/images/a.png" loading="lazy" decoding="async"></p>', $onAbout->blocks('left')[0]['html']);

        $onHome = new Layout(ctx(true, null, $ids['news']), 'pl');
        same([$html], $blockIds($onHome, 'left'));
        same([$except], $blockIds($onHome, 'right'));
        same([$news, $home], $blockIds($onHome, 'footer'));
        same([$news], $blockIds(new Layout(ctx(page: $ids['a'], category: $ids['news']), 'pl'), 'footer'), 'a category covers its pages');
        same([$except], $blockIds(new Layout(null, 'pl'), 'right'), '404 pages count as "except"');

        // English: only blocks with English content.
        same([$only], $blockIds(new Layout(ctx(page: $ids['about']), 'en'), 'left'));

        // Ordering within the position; switching a panel off hides it.
        same(true, Blocks::move($only, -1));
        same([$only, $html], $blockIds(new Layout(ctx(page: $ids['about']), 'pl'), 'left'));
        Settings::set('template', ['show_left' => false] + Settings::TEMPLATE);
        same(false, (new Layout(ctx(page: $ids['about']), 'pl'))->has('left'));
        Blocks::setState([$menuBlock], 0);
        same(false, (new Layout(ctx(page: $ids['about']), 'pl'))->has('top'));
    },

    'blocks: hero' => function () {
        $ids = layout_fixture();
        $data = fn (array $over = []) => $over + ['title' => 'Baner', 'type' => 'hero', 'position' => 'left', 'menu_id' => 0, 'state' => 1,
            'show_title' => false, 'assignment' => 'all', 'targets' => [], 'css_class' => '', 'image' => 'images/hero/a.jpg',
            'params' => ['height' => 'lg', 'align' => 'center', 'overlay' => 25, 'link_type' => 'page', 'link_page' => $ids['about']],
            'translations' => ['pl' => ['title' => 'Witaj', 'text' => "Linia 1\nLinia 2", 'link_label' => 'Zobacz']]];

        // Validation.
        same(['image'], array_keys(Blocks::save(0, $data(['image' => '../x.php']))[1]));
        same(['image'], array_keys(Blocks::save(0, $data(['image' => '', 'translations' => []]))[1]));
        same(['link_page'], array_keys(Blocks::save(0, $data(['params' => ['link_type' => 'page', 'link_page' => 9999]]))[1]));
        same(['link_url'], array_keys(Blocks::save(0, $data(['params' => ['link_type' => 'url', 'link_url' => 'javascript:alert(1)']]))[1]));

        // Saved in its own position whatever was posted; unknown options fall back to defaults.
        [$id, $err] = Blocks::save(0, $data());
        same([], $err);
        $block = Blocks::find($id);
        same(['hero', 'images/hero/a.jpg', 'lg', 'center', 25], [$block['position'], $block['image'], $block['params']['height'], $block['params']['align'], $block['params']['overlay']]);
        same('md', Blocks::params(['params' => '{"height":"md"}'])['height']);

        // On the site: heading, text, button to the page's URL.
        $hero = (new Layout(ctx(page: $ids['about']), 'pl'))->blocks('hero')[0]['hero'];
        same(['Witaj', "Linia 1\nLinia 2", 'images/hero/a.jpg'], [$hero['heading'], $hero['text'], $hero['image']]);
        same('Zobacz', $hero['button']['label']);
        check(str_ends_with($hero['button']['url'], '/o-nas'), $hero['button']['url']);

        // Not translated: hidden there. Only an image: shown in every language. No label: no button.
        same([], (new Layout(ctx(page: $ids['about']), 'en'))->blocks('hero'));
        [$imageOnly] = Blocks::save(0, $data(['translations' => []]));
        same(1, count((new Layout(ctx(page: $ids['about']), 'en'))->blocks('hero')));
        same(null, (new Layout(ctx(page: $ids['about']), 'pl'))->blocks('hero')[1]['hero']['button']);
        Blocks::delete([$imageOnly]);

        // A button whose target isn't visible in the language disappears with it.
        Pages::setState([$ids['about']], 0, 1);
        same(null, (new Layout(ctx(home: true), 'pl'))->blocks('hero')[0]['hero']['button']);
        Pages::setState([$ids['about']], 1, 1);

        // History: edits are kept and restored, also after deleting.
        Blocks::save($id, $data(['image' => 'images/hero/b.jpg']));
        $old = Pageyard\Revisions::forItem('block', $id)[0];
        same([], Pageyard\Revisions::restore((int) $old['id'], 1)[2]);
        same('images/hero/a.jpg', Blocks::find($id)['image']);
        Blocks::delete([$id]);
        same(null, Blocks::find($id));
        $deleted = Pageyard\Revisions::forItem('block', $id)[0];
        same([], Pageyard\Revisions::restore((int) $deleted['id'], 1)[2]);
        same(['hero', 'hero', 0, 'images/hero/a.jpg', 'Zobacz'], [Blocks::find($id)['type'], Blocks::find($id)['position'], (int) Blocks::find($id)['state'], Blocks::find($id)['image'], Blocks::find($id)['translations']['pl']['link_label']]);

        // The library knows the image is in use.
        check(Pageyard\Media::usage(['images/hero/a.jpg'])['images/hero/a.jpg'] !== []);
    },

    'layout: side menu' => function () {
        $ids = layout_fixture();
        $left = ['title' => 'Boczne', 'type' => 'html', 'position' => 'left', 'menu_id' => 0, 'state' => 1, 'show_title' => false,
            'assignment' => 'only', 'targets' => ['page:' . $ids['about']], 'css_class' => '', 'translations' => ['pl' => ['title' => '', 'content' => '<p>x</p>']]];
        same([], Blocks::save(0, $left)[1]);
        $on = new Layout(ctx(page: $ids['about']), 'pl');
        same([false, 'container'], [$on->sidebar(), $on->wrapper()], 'the default layout has no side menu');

        Settings::set('template', ['layout' => 'sidebar'] + Settings::TEMPLATE);
        $on = new Layout(ctx(page: $ids['about']), 'pl');
        same([true, 'container-fluid'], [$on->sidebar(), $on->wrapper()]);
        check(str_contains($on->render('left', 'sidebar'), '<p>x</p>'));
        same(false, (new Layout(ctx(true), 'pl'))->sidebar(), 'no left blocks on this view: no side menu');
    },

    'settings: template options and custom CSS' => function () {
        layout_fixture();
        Settings::reset();
        same(Settings::TEMPLATE, Settings::template());
        [$o, $err] = Settings::validateTemplate(['container' => 'container-fluid', 'navbar' => 'evil', 'left_width' => '9', 'show_left' => '1', 'logo' => '../x.php']);
        same(['logo'], array_keys($err));
        same(['container-fluid', 'dark', 3, true, false], [$o['container'], $o['navbar'], $o['left_width'], $o['show_left'], $o['show_right']]);
        same([], Settings::validateTemplate(['logo' => 'images/site/logo.svg'])[1]);
        same('panels', Settings::validateTemplate(['layout' => 'evil'])[0]['layout']);
        same('sidebar', Settings::validateTemplate(['layout' => 'sidebar'])[0]['layout']);
        // Options stored by the former "Clean" look.
        Settings::set('template', ['navbar' => 'auto', 'accent' => '#ff0000'] + Settings::TEMPLATE);
        same('dark', Settings::template()['navbar']);
        check(!isset(Settings::template()['accent']));
        Settings::set('custom_css', "body { color: red; }\n/* </style><script>alert(1)</script> */");
        Settings::reset();
        same("body { color: red; }\n/* <\\/style><script>alert(1)</script> */", Settings::customCss());
        check(!str_contains(Settings::customCss(), '</style'));
    },
];
