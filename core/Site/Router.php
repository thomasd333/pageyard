<?php

namespace Pageyard\Site;

use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Home;
use Pageyard\Pages;
use Pageyard\Redirects;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * Site URLs come from the content's aliases:
 *   /<page alias>                      a page without a category
 *   /<category alias>                  a category (its list of pages)
 *   /<category alias>/<page alias>     a page in a category
 * in the default language, with a /<lang>/ prefix in the others. The home page (a page or a category)
 * lives at / (or /<lang>/); its own address redirects there. Without URL rewriting paths follow index.php/.
 */
final class Router
{
    /** @var array<int, array{state: int, access: int, alias: array<string, string>}>|null published-or-not categories with aliases */
    private static ?array $categories = null;

    /** Forgets cached lookups (after changes, in tests). */
    public static function reset(): void
    {
        self::$categories = null;
    }

    /** @return array<int, array{state: int, access: int, alias: array<string, string>, title: array<string, string>}> */
    public static function categories(): array
    {
        if (self::$categories === null) {
            self::$categories = [];
            $rows = Db::get()->fetchAll('SELECT c.id, c.state, c.access, t.lang, t.alias, t.title FROM #__categories c
                JOIN ' . Categories::T . ' t ON t.category_id = c.id');
            foreach ($rows as $r) {
                self::$categories[(int) $r['id']]['state'] = (int) $r['state'];
                self::$categories[(int) $r['id']]['access'] = (int) $r['access'];
                self::$categories[(int) $r['id']]['alias'][$r['lang']] = $r['alias'];
                self::$categories[(int) $r['id']]['title'][$r['lang']] = $r['title'];
            }
        }
        return self::$categories;
    }

    /** The request path relative to the site root, without index.php, query or surrounding slashes. */
    public static function requestPath(): string
    {
        $path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        $root = Uri::root();
        if (str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        } elseif ($path . '/' === $root) {
            $path = '';
        }
        if ($path === 'index.php') {
            $path = '';
        } elseif (str_starts_with($path, 'index.php/')) {
            $path = substr($path, 10);
        }
        return $path;
    }

    /**
     * Works out what a request path points at. Visibility of the page itself (published, date,
     * translation) is checked by the caller.
     *
     * @param bool $redirects false = only what the content itself answers
     * @return array{lang: string, type: ?string, id: ?int, home: bool, redirect: ?string, status: int}
     *         type 'page' or 'category' with status 200, a redirect with 301, or status 404
     */
    public static function resolve(string $path, bool $redirects = true): array
    {
        $default = (string) Config::get('language');
        $languages = Config::languages();
        $trailing = $path !== '' && str_ends_with($path, '/');
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));

        $lang = $default;
        if ($segments && $segments[0] === $default && count($languages) > 1) {
            // The default language has no prefix.
            array_shift($segments);
            return self::redirectTo(self::url(implode('/', $segments), $default), $default);
        }
        if ($segments && $segments[0] !== $default && in_array($segments[0], $languages, true)) {
            $lang = array_shift($segments);
        }
        $result = ['lang' => $lang, 'type' => null, 'id' => null, 'home' => false, 'redirect' => null, 'status' => 404];

        if (!$segments) {
            $home = self::home($lang);
            return $home === null ? $result : ['type' => $home['type'], 'id' => $home['id'], 'home' => true, 'status' => 200] + $result;
        }

        $target = null;  // [type, id, canonical url]
        if (count($segments) === 1) {
            $categoryId = self::categoryByAlias($segments[0], $lang);
            if ($categoryId !== null) {
                $target = ['category', $categoryId, self::categoryUrl($categoryId, $lang)];
            } else {
                $page = self::pageByAlias($segments[0], $lang);
                if ($page !== null) {
                    $target = ['page', $page['id'], self::pageUrl($page['id'], $page['category_id'], $segments[0], $lang)];
                }
            }
        } elseif (count($segments) === 2) {
            $page = self::pageByAlias($segments[1], $lang);
            if ($page !== null && self::categoryByAlias($segments[0], $lang) !== null) {
                $target = ['page', $page['id'], self::pageUrl($page['id'], $page['category_id'], $segments[1], $lang)];
            }
        }
        if ($target === null || $target[2] === null) {
            $old = $redirects ? self::redirected($segments, $lang) : null;
            return $old !== null ? self::redirectTo($old, $lang) : $result;
        }
        // Home page under its own address, a page moved to another category, a trailing slash: one canonical URL.
        if ($target[2] !== self::url(implode('/', $segments), $lang) || $trailing) {
            return self::redirectTo($target[2], $lang);
        }
        return ['type' => $target[0], 'id' => $target[1], 'status' => 200] + $result;
    }

    /**
     * Where an old address leads, or null. Pages of a renamed category have no row of their own:
     * /<old category>/<page> goes through the category's row. A target visitors can't see gives null.
     * @param list<string> $segments
     */
    private static function redirected(array $segments, string $lang): ?string
    {
        $row = Redirects::find($lang, implode('/', $segments));
        if ($row === null && count($segments) === 2) {
            $category = Redirects::find($lang, $segments[0]);
            if ($category === null || $category['target_type'] !== 'category') {
                return null;
            }
            $page = self::pageByAlias($segments[1], $lang);
            if ($page !== null) {
                $url = self::redirectedPage($page['id'], $lang);
                if ($url !== null) {
                    Redirects::hit((int) $category['id']);
                }
                return $url;
            }
            $alias = self::categories()[(int) $category['target_id']]['alias'][$lang] ?? null;
            $row = $alias !== null ? Redirects::find($lang, "$alias/{$segments[1]}") : null;
        }
        if ($row === null) {
            return null;
        }
        $id = (int) $row['target_id'];
        $url = match ($row['target_type']) {
            'page'     => self::redirectedPage($id, $lang),
            'category' => (self::categories()[$id]['state'] ?? 0) ? self::categoryUrl($id, $lang) : null,
            default    => Redirects::targetAddress($row['url']),
        };
        if ($url !== null) {
            Redirects::hit((int) $row['id']);
        }
        return $url;
    }

    private static function redirectedPage(int $id, string $lang): ?string
    {
        $page = Content::pageLink($id, $lang);
        return $page === null ? null : self::pageUrl($id, $page['category_id'] !== null ? (int) $page['category_id'] : null, $page['alias'], $lang);
    }

    /**
     * The home target if it is set and its language version exists (published category with a
     * translation; for a page the caller checks visibility).
     * @return array{type: string, id: int}|null
     */
    public static function home(string $lang): ?array
    {
        $home = Home::get();
        if ($home === null) {
            return null;
        }
        if ($home['type'] === 'category') {
            $c = self::categories()[$home['id']] ?? null;
            return $c !== null && $c['state'] && isset($c['alias'][$lang]) ? $home : null;
        }
        $exists = Db::get()->fetchValue('SELECT 1 FROM ' . Pages::T . ' WHERE page_id = ? AND lang = ?', [$home['id'], $lang]);
        return $exists ? $home : null;
    }

    /** URL of a path in a language ('' = that language's home). */
    public static function url(string $path, string $lang, array $query = []): string
    {
        $prefix = $lang !== (string) Config::get('language') ? $lang . ($path !== '' ? '/' : '') : '';
        $rel = $prefix . $path;
        $url = Uri::root() . ($rel !== '' && !Config::get('sef_rewrite', true) ? 'index.php/' : '') . $rel;
        if ($lang !== (string) Config::get('language') && $path === '') {
            $url .= '/';
        }
        return $url . ($query ? '?' . http_build_query($query) : '');
    }

    /**
     * URL of a page in $lang ($alias = its alias there), or null when its category has no
     * translation in that language.
     */
    public static function pageUrl(int $pageId, ?int $categoryId, string $alias, string $lang): ?string
    {
        if (Home::is('page', $pageId)) {
            return self::url('', $lang);
        }
        if ($categoryId === null) {
            return self::url($alias, $lang);
        }
        $categoryAlias = self::categories()[$categoryId]['alias'][$lang] ?? null;
        return $categoryAlias === null ? null : self::url("$categoryAlias/$alias", $lang);
    }

    /** URL of a category's list in $lang, or null without a translation there. */
    public static function categoryUrl(int $categoryId, string $lang): ?string
    {
        $alias = self::categories()[$categoryId]['alias'][$lang] ?? null;
        if ($alias === null) {
            return null;
        }
        return Home::is('category', $categoryId) ? self::url('', $lang) : self::url($alias, $lang);
    }

    /** Published category with this alias in $lang. */
    private static function categoryByAlias(string $alias, string $lang): ?int
    {
        foreach (self::categories() as $id => $c) {
            if ($c['state'] && ($c['alias'][$lang] ?? null) === $alias) {
                return $id;
            }
        }
        return null;
    }

    /** @return array{id: int, category_id: ?int}|null */
    private static function pageByAlias(string $alias, string $lang): ?array
    {
        $row = Db::get()->fetchRow('SELECT p.id, p.category_id FROM #__pages p
            JOIN ' . Pages::T . ' t ON t.page_id = p.id WHERE t.lang = ? AND t.alias = ?', [$lang, $alias]);
        return $row === null ? null : ['id' => (int) $row['id'], 'category_id' => $row['category_id'] !== null ? (int) $row['category_id'] : null];
    }

    private static function redirectTo(string $url, string $lang): array
    {
        return ['lang' => $lang, 'type' => null, 'id' => null, 'home' => false, 'redirect' => $url, 'status' => 301];
    }
}
