<?php

use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Database\Migrator;
use Pageyard\Media;
use Pageyard\Pages;
use Pageyard\Settings;
use Pageyard\Site\Router;
use Pageyard\Str;
use Pageyard\Translations;

defined('PAGEYARD') or exit;

/** Content languages pl (default) + en, timezone Europe/Warsaw. */
function content_config(): void
{
    Config::set(['language' => 'pl', 'languages' => 'pl,en', 'timezone' => 'Europe/Warsaw']);
    date_default_timezone_set('Europe/Warsaw');
}

function page_data(array $translations, array $overrides = []): array
{
    return array_merge(['state' => 1, 'category_id' => 0, 'image' => '', 'publish_up' => '2026-11-03T10:00',
        'translations' => $translations], $overrides);
}

/** The pages/categories workflow; run on SQLite and (optionally) MySQL. */
function content_workflow(Db $db): void
{
    Settings::reset();  // the home page of an earlier test's database
    Db::setInstance($db);
    content_config();

    [$cat, $err] = Categories::save(0, ['state' => 1, 'translations' => [
        'pl' => ['title' => 'Aktualności', 'alias' => '', 'description' => '<p>Opis</p>'],
        'en' => ['title' => 'News', 'alias' => '', 'description' => ''],
    ]]);
    same([], $err);
    $aliases = array_map(fn ($r) => $r['alias'], Categories::find($cat)['translations']);
    ksort($aliases);
    same(['en' => 'news', 'pl' => 'aktualnosci'], $aliases);
    same(null, Categories::find($cat)['translations']['en']['description']);

    // Default language is required, other languages optional.
    [, $err] = Pages::save(0, page_data(['pl' => ['title' => ' '], 'en' => ['title' => 'Only English']]), 1);
    same(['pl.title'], array_keys($err));

    [$p1, $err] = Pages::save(0, page_data([
        'pl' => ['title' => 'Zażółć gęślą jaźń', 'alias' => '', 'intro' => '<p>Wstęp</p>', 'body' => '<p>&nbsp;</p>', 'meta_description' => 'Opis'],
        'en' => ['title' => '', 'alias' => 'ignored'],
    ], ['category_id' => $cat]), 1);
    same([], $err);
    $page = Pages::find($p1);
    same(['pl'], array_keys($page['translations']), 'empty optional language has no row');
    same('zazolc-gesla-jazn', $page['translations']['pl']['alias']);
    same(null, $page['translations']['pl']['body'], 'an emptied editor is stored as null');
    same('2026-11-03 09:00:00', $page['publish_up'], 'local date stored in UTC');
    same($cat, (int) $page['category_id']);
    same(1, (int) $page['created_by']);

    // Same title again: alias gets a number and the caller is told.
    [$p2, $err, $renamed] = Pages::save(0, page_data(['pl' => ['title' => 'Zażółć gęślą jaźń'], 'en' => ['title' => 'Hello', 'alias' => 'My Alias!']]), 1);
    same([], $err);
    same(['pl'], $renamed);
    same('zazolc-gesla-jazn-2', Pages::find($p2)['translations']['pl']['alias']);
    same('my-alias', Pages::find($p2)['translations']['en']['alias']);
    // Re-saving keeps its own alias (not "-3").
    [, , $renamed] = Pages::save($p2, page_data(['pl' => ['title' => 'Zażółć gęślą jaźń', 'alias' => 'zazolc-gesla-jazn-2']]), 1);
    same([], $renamed);
    same(['pl'], array_keys(Pages::find($p2)['translations']), 'removing a translation deletes its row');

    [, $err] = Pages::save(0, page_data(['pl' => ['title' => 'X']], ['category_id' => 999, 'image' => '../config.php', 'publish_up' => '2026-13-45']), 1);
    same(['category_id', 'image', 'publish_up'], array_keys($err));

    [$p3] = Pages::save(0, page_data(['pl' => ['title' => '100% _test_']], ['state' => 0, 'publish_up' => '2026-01-01T00:00']), 1);

    $state = ['search' => '', 'state' => '', 'category' => '', 'sort' => 'publish_up', 'dir' => 'desc'];
    $list = Pages::list($state, 'pl', 0, 0);
    same(3, $list['total']);
    same([$p2, $p1, $p3], array_column($list['items'], 'id'), 'newest first, then id');
    same('Aktualności', $list['items'][1]['category']);
    same(['pl'], $list['items'][1]['languages']);
    same([$p3], array_column(Pages::list(['state' => '0'] + $state, 'pl', 0, 0)['items'], 'id'));
    same([$p1], array_column(Pages::list(['category' => (string) $cat] + $state, 'pl', 0, 0)['items'], 'id'));
    same([$p2, $p3], array_column(Pages::list(['category' => 'none'] + $state, 'pl', 0, 0)['items'], 'id'));
    same([$p3], array_column(Pages::list(['search' => '_t'] + $state, 'pl', 0, 0)['items'], 'id'), 'LIKE wildcards are literal');
    same([$p3], array_column(Pages::list(['search' => '100%'] + $state, 'pl', 0, 0)['items'], 'id'));
    same([$p2, $p1], array_column(Pages::list(['search' => 'ZAŻÓŁĆ'] + $state, 'pl', 2, 0)['items'], 'id'), 'search ignores case (ASCII-folded aliases match)');
    same([$p3, $p2], array_column(Pages::list(['sort' => 'title', 'dir' => 'asc'] + $state, 'pl', 2, 0)['items'], 'id'), 'equal titles: newest id first');

    same(2, Pages::setState([$p1, $p2], 0, 1));
    same(0, (int) Pages::find($p1)['state']);

    // A category with pages is not deleted.
    $clist = Categories::list(['search' => '', 'state' => '', 'sort' => 'title', 'dir' => 'asc'], 'pl', 0, 0);
    same(1, (int) $clist['items'][0]['pages']);
    $langs = $clist['items'][0]['languages'];
    sort($langs);
    same(['en', 'pl'], $langs);
    same([0, ['Aktualności']], Categories::delete([$cat], 'pl'));
    same(1, Pages::delete([$p1]));
    same(0, (int) $db->fetchValue('SELECT COUNT(*) FROM #__page_translations WHERE page_id = ?', [$p1]));
    same([1, []], Categories::delete([$cat], 'pl'));
    same(0, (int) $db->fetchValue('SELECT COUNT(*) FROM #__category_translations'));
    same([], Categories::options('pl'));
}

/** A small real PNG in a temp file. */
function temp_png(): string
{
    $file = tempnam(sys_get_temp_dir(), 'py') . '.png';
    $img = imagecreatetruecolor(4, 3);
    imagepng($img, $file);
    return $file;
}

/** Titles of a category's pages as the site lists them. */
function category_titles(int $id, string $order): array
{
    return array_column(Pageyard\Site\Content::categoryPages($id, 'pl', $order, 100, 0)['items'], 'title');
}

return [
    'pages: one text in the editor, cut at the "Read more" line' => function () {
        $line = Pages::READ_MORE;
        same(['', '<p>a</p>'], Pages::splitText('<p>a</p>'), 'no line');
        same(['', ''], Pages::splitText(''));
        same(['<p>a</p>', '<p>b</p>'], Pages::splitText("<p>a</p>\n$line\n<p>b</p>"));
        same(['<p>a</p>', '<p>b</p>'], Pages::splitText('<p>a</p><hr class="x py-readmore" data-y="1" /><p>b</p>'), 'other attributes');
        same(['<p>a</p>', '<p>b</p><p>c</p>'], Pages::splitText("<p>a</p>$line<p>b</p>$line<p>c</p>"), 'the first counts');
        same(['', '<p>a</p>'], Pages::splitText("<p>a</p>$line"), 'nothing after it');
        same(['', '<p>a</p>'], Pages::splitText("$line<p>a</p>"), 'nothing before it');
        same(['', '<p>a</p>'], Pages::splitText("<p>&nbsp;</p>$line<p>a</p>"));
        same(['<p>a</p>', '<p>b</p>'], Pages::splitText("<p>a</p><p>&nbsp;</p>$line<p>&nbsp;</p><p>b</p>"), 'empty paragraphs at the line');
        same(['<p>a</p>', '<hr><p>b</p>'], Pages::splitText("<p>a</p>$line<hr><p>b</p>"), 'an ordinary line stays');
        same(['<p>a<br>b</p><img src="x"><br />', '<p>c</p>'], Pages::splitText("<p>a<br>b</p><img src=\"x\"><br />$line<p>c</p>"), 'void elements');
        // Never cut inside another element
        same(['', '<table><tr><td>a</td></tr></table>'], Pages::splitText("<table><tr><td>a$line</td></tr></table>"));
        same(['<ul><li>a</li></ul>', '<p>b</p>'], Pages::splitText("<ul><li>a$line</li></ul>$line<p>b</p>"), 'the next line that can be used');

        same('', Pages::joinText(null, null));
        same('<p>a</p>', Pages::joinText('<p>a</p>', null), 'no line for an intro alone');
        same('<p>b</p>', Pages::joinText(null, '<p>b</p>'));
        same(['<p>a</p>', '<p>b</p>'], Pages::splitText(Pages::joinText('<p>a</p>', '<p>b</p>')), 'there and back');
    },

    'pages: what a blog list shows, with and without the line' => function () {
        site_fixture();
        $teaser = Pageyard\Site\Content::teaser(...);
        $long = '<p>' . str_repeat('słowo ', 60) . '</p><p><strong>koniec</strong></p>';

        same(['html' => '<p>a</p>', 'more' => true], $teaser(['intro' => '<p>a</p>', 'body' => '<p>b</p>']));
        same(['html' => '<p>a</p>', 'more' => false], $teaser(['intro' => '<p>a</p>', 'body' => null]), 'an older intro alone');
        same(['html' => '', 'more' => false], $teaser(['intro' => null, 'body' => null]));

        same(['html' => $long, 'more' => false], $teaser(['intro' => null, 'body' => $long]), 'whole by default');

        site_config(['blog_text' => 'excerpt']);
        same(['html' => '<p>a</p>', 'more' => true], $teaser(['intro' => '<p>a</p>', 'body' => '<p>b</p>']), 'the line still decides');
        same(['html' => '<p><b>a</b></p>', 'more' => false], $teaser(['intro' => null, 'body' => '<p><b>a</b></p>']), 'short stays whole');
        $cut = $teaser(['intro' => null, 'body' => $long]);
        same(true, $cut['more']);
        check(str_starts_with($cut['html'], '<p>słowo') && str_ends_with($cut['html'], '…</p>') && mb_strlen($cut['html']) <= 210, $cut['html']);
        same(true, $teaser(['intro' => null, 'body' => '<p>a</p><img src="images/x.png">'])['more'], 'a short text with an image');
        same(['html' => '', 'more' => true], $teaser(['intro' => null, 'body' => '<img src="images/x.png">']), 'only an image');
        same(['html' => '<p>a</p>', 'more' => true], $teaser(['intro' => null, 'body' => '<p>a</p>{script built-in/contact}']), 'scripts do not run in the start');
        same(['html' => '<p>&lt;a &amp; b</p>', 'more' => true], $teaser(['intro' => null, 'body' => '<p>&lt;a &amp; b</p><img src="x">']), 'escaped');
        site_config();
    },

    'pages: moving to a category, redirects and history included' => function () {
        $ids = site_fixture();
        [$other] = Categories::save(0, ['state' => 1, 'params' => [], 'translations' => ['pl' => ['title' => 'Inna']]]);
        Router::reset();
        same(2, Pages::moveTo([$ids['a'], $ids['about']], $other, 1));
        Router::reset();
        same($other, (int) Pages::find($ids['a'])['category_id']);
        same([200, 'page', $ids['a']], resolved('inna/pierwszy-wpis'));
        same([200, 'page', $ids['about']], resolved('inna/o-nas'));
        same([301, '/cms/inna/o-nas'], resolved('o-nas'), 'its old address');
        same(1, Pageyard\Revisions::count('page', $ids['about']), 'in the history');

        same(0, Pages::moveTo([$ids['a']], $other, 1), 'already there');
        same(0, Pages::moveTo([99999], $other, 1), 'no such page');
        same(1, Pages::moveTo([$ids['a']], null, 1));
        Router::reset();
        same(null, Pages::find($ids['a'])['category_id']);
        same([200, 'page', $ids['a']], resolved('pierwszy-wpis'));
    },

    'pages: manual order within a category' => function () {
        site_fixture();
        [$cat] = Categories::save(0, ['state' => 1, 'params' => ['order' => 'manual'], 'translations' => ['pl' => ['title' => 'Kolejka']]]);
        same('manual', Categories::params(Categories::find($cat))['order']);
        $made = [];
        foreach (['Pierwsza', 'Druga', 'Trzecia'] as $title) {
            [$made[]] = Pages::save(0, page_data(['pl' => ['title' => $title]], ['category_id' => $cat, 'publish_up' => '2026-01-01T00:00']), 1);
        }
        same(['Pierwsza', 'Druga', 'Trzecia'], category_titles($cat, 'manual'), 'new ones go to the end');

        check(Pages::moveInOrder($made[2], -1));
        same(['Pierwsza', 'Trzecia', 'Druga'], category_titles($cat, 'manual'));
        check(Pages::moveInOrder($made[0], 1));
        same(['Trzecia', 'Pierwsza', 'Druga'], category_titles($cat, 'manual'));
        check(!Pages::moveInOrder($made[2], -1), 'at the top');
        check(!Pages::moveInOrder($made[1], 1), 'at the bottom');
        check(!Pages::moveInOrder(99999, 1));

        // Saving keeps the place; leaving and coming back puts it at the end.
        Pages::save($made[2], page_data(['pl' => ['title' => 'Trzecia']], ['category_id' => $cat, 'publish_up' => '2026-01-01T00:00']), 1);
        same(['Trzecia', 'Pierwsza', 'Druga'], category_titles($cat, 'manual'));
        Pages::moveTo([$made[2]], null, 1);
        Pages::moveTo([$made[2]], $cat, 1);
        same(['Pierwsza', 'Druga', 'Trzecia'], category_titles($cat, 'manual'));
        same(['Pierwsza', 'Druga', 'Trzecia'], array_column(Pages::list(['search' => '', 'state' => '', 'category' => (string) $cat, 'sort' => 'ordering', 'dir' => 'asc'], 'pl', 10, 0)['items'], 'title'));
    },

    'migration 0011: pages start in the order visitors see' => function () {
        $db = memory_db();
        (new Migrator($db))->migrate();
        Db::setInstance($db);
        Settings::reset();
        site_config();
        [$cat] = Categories::save(0, ['state' => 1, 'params' => [], 'translations' => ['pl' => ['title' => 'K']]]);
        foreach (['Stara' => '2026-01-01T00:00', 'Nowa' => '2026-03-01T00:00', 'Srednia' => '2026-02-01T00:00'] as $title => $date) {
            Pages::save(0, page_data(['pl' => ['title' => $title]], ['category_id' => $cat, 'publish_up' => $date]), 1);
        }
        $db->query('UPDATE #__pages SET ordering = 0');
        $db->exec('DROP INDEX ' . $db->qn($db->table('#__pages') . '_category_id_ordering_idx'));
        $db->exec('ALTER TABLE ' . $db->table('#__pages') . ' DROP COLUMN ordering');
        (require PY_ROOT . '/core/Database/migrations/0011_pages_ordering.php')(new Pageyard\Database\Schema($db), $db);
        same(['Nowa', 'Srednia', 'Stara'], category_titles($cat, 'manual'));
    },

    'str: slug and excerpt' => function () {
        same('zazolc-gesla-jazn', Str::slug('Zażółć gęślą jaźń!'));
        same('strasse-ubung-cafe', Str::slug('Straße — Übung, café'));
        same('privit-svit', Str::slug('Привіт, світ'));
        same('', Str::slug('!!!'));
        same('abc', Str::slug('ABC-', 3));
        same('Ala ma kota i…', Str::excerpt('<p>Ala ma <b>kota</b> i psa</p>', 14));
        same('Ala ma…', Str::excerpt('<p>Ala ma <b>kota</b></p>', 9));
        same('Krótki &', Str::excerpt('<p>Krótki &amp;</p>'));
    },

    'translations: unique alias per language' => function () {
        $db = memory_db();
        (new Migrator($db))->migrate();
        Db::setInstance($db);
        $db->insert(Pages::T, ['page_id' => 1, 'lang' => 'pl', 'title' => 'A', 'alias' => 'a']);
        $db->insert(Pages::T, ['page_id' => 2, 'lang' => 'pl', 'title' => 'A', 'alias' => 'a-2']);
        same('a-3', Translations::uniqueAlias(Pages::T, 'page_id', 0, 'pl', 'a'));
        same('a', Translations::uniqueAlias(Pages::T, 'page_id', 1, 'pl', 'a'), 'own alias is fine');
        same('a', Translations::uniqueAlias(Pages::T, 'page_id', 0, 'en', 'a'), 'other language is fine');
    },

    'pages: date parsing in the site time zone' => function () {
        content_config();
        same('2026-07-01 08:30:00', Pages::parseLocalDate('2026-07-01T10:30'), 'summer time: UTC+2');
        same('2026-11-30 23:00:00', Pages::parseLocalDate('2026-12-01'));
        same(null, Pages::parseLocalDate('2026-02-30T10:00'));
        same(null, Pages::parseLocalDate('yesterday'));
    },

    'content: pages and categories workflow (SQLite)' => function () {
        $db = memory_db();
        (new Migrator($db))->migrate();
        content_workflow($db);
    },

    'content: pages and categories workflow (MySQL)' => function () {
        if (!($opt = mysql_options())) {
            return 'skip';
        }
        $name = 'pageyard_test_' . bin2hex(random_bytes(3));
        $server = Db::connect($opt);
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $db = Db::connect(['db' => $name] + $opt);
            (new Migrator($db))->migrate();
            content_workflow($db);
        } finally {
            $server->exec("DROP DATABASE `$name`");
        }
    },

    'media: stores images, rejects everything else' => function () {
        $root = temp_root();
        $png = temp_png();
        $path = Media::storeUpload(['name' => 'Zdjęcie Główne.PNG', 'tmp_name' => $png, 'error' => 0, 'size' => filesize($png)], 'pages', $root);
        same('images/pages/' . date('Y-m') . '/zdjecie-glowne.png', $path);
        check(is_file("$root/$path"));
        $again = Media::storeUpload(['name' => 'zdjecie glowne.png', 'tmp_name' => $png, 'error' => 0], 'pages', $root);
        same('images/pages/' . date('Y-m') . '/zdjecie-glowne-2.png', $again);

        $fake = tempnam(sys_get_temp_dir(), 'py');
        file_put_contents($fake, '<?php echo 1;');
        throws(fn () => Media::storeUpload(['name' => 'x.jpg', 'tmp_name' => $fake, 'error' => 0], 'pages', $root), RuntimeException::class);
        throws(fn () => Media::storeUpload(['name' => 'x.php', 'tmp_name' => $png, 'error' => 0], 'pages', $root), RuntimeException::class);
        file_put_contents($fake, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        throws(fn () => Media::storeUpload(['name' => 'x.svg', 'tmp_name' => $fake, 'error' => 0], 'pages', $root), RuntimeException::class);
        throws(fn () => Media::storeUpload(['error' => UPLOAD_ERR_INI_SIZE], 'pages', $root), RuntimeException::class, 'MB');
        throws(fn () => Media::storeUpload(['name' => 'a.png', 'tmp_name' => $png, 'error' => 0], '../x', $root), InvalidArgumentException::class);
        unlink($fake);
        unlink($png);

        check(Media::isImagePath('images/pages/2026-11/a.webp'));
        check(!Media::isImagePath('images/../configuration.php'));
        check(!Media::isImagePath('images/a.php'));
        check(!Media::isImagePath('/etc/passwd.png'));
    },
];
