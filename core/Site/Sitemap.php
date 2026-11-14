<?php

namespace Pageyard\Site;

use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Home;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * /sitemap.xml and /robots.txt, generated on request.
 * The sitemap lists the home page, published categories and visible pages in every enabled language,
 * with lastmod and (with several languages) xhtml:link alternates between the language versions.
 */
final class Sitemap
{
    /**
     * One entry per thing shown on the site: its URL per language and the last change.
     * @return list<array{urls: array<string, string>, lastmod: string}> urls are root-relative, lastmod UTC
     */
    public static function entries(): array
    {
        $languages = Config::languages();
        $items = [];  // key => [urls => [lang => url], lastmod]
        $add = function (string $key, string $lang, ?string $url, ?string $lastmod) use (&$items, $languages): void {
            if ($url === null || !in_array($lang, $languages, true)) {
                return;
            }
            $items[$key]['urls'][$lang] = $url;
            $items[$key]['lastmod'] = max($items[$key]['lastmod'] ?? '', (string) $lastmod);
        };
        $db = Db::get();

        // The home page.
        $home = Home::get();
        if ($home !== null) {
            $table = $home['type'] === 'page' ? '#__pages' : '#__categories';
            $modified = (string) $db->fetchValue("SELECT modified FROM $table WHERE id = ?", [$home['id']]);
            foreach ($languages as $lang) {
                $visible = $home['type'] === 'page' ? Content::pageLink($home['id'], $lang) !== null : Router::home($lang) !== null;
                $add('home', $lang, $visible ? Router::url('', $lang) : null, $modified);
            }
        }

        // Categories (their lists); the home category is the home page above.
        $modified = array_column($db->fetchAll('SELECT id, modified FROM #__categories WHERE state = 1'), 'modified', 'id');
        foreach (Router::categories() as $id => $category) {
            if (!$category['state'] || $category['access'] !== 0 || Home::is('category', $id)) {
                continue;
            }
            foreach (array_keys($category['alias']) as $lang) {
                $add("category:$id", $lang, Router::categoryUrl($id, $lang), $modified[$id] ?? null);
            }
        }

        // Pages; a category's list changes when one of its pages does.
        foreach (Content::visibleTranslations() as $page) {
            $id = (int) $page['id'];
            $category = $page['category_id'] !== null ? (int) $page['category_id'] : null;
            $changed = max($page['modified'], $page['publish_up']);
            if (Home::is('page', $id)) {
                continue;
            }
            $add("page:$id", $page['lang'], Router::pageUrl($id, $category, $page['alias'], $page['lang']), $changed);
            if ($category !== null && isset($items["category:$category"]['urls'][$page['lang']])) {
                $add("category:$category", $page['lang'], $items["category:$category"]['urls'][$page['lang']], $changed);
            }
        }
        // Languages in the configured order (default first).
        $order = array_flip($languages);
        foreach ($items as &$item) {
            uksort($item['urls'], fn ($a, $b) => $order[$a] <=> $order[$b]);
        }
        unset($item);
        return array_values($items);
    }

    public static function xml(): string
    {
        $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach (self::entries() as $entry) {
            $lastmod = $entry['lastmod'] !== ''
                ? (new \DateTimeImmutable($entry['lastmod'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')
                : null;
            foreach ($entry['urls'] as $url) {
                $out .= "  <url>\n    <loc>" . $esc(Uri::absolute($url)) . "</loc>\n";
                if ($lastmod !== null) {
                    $out .= "    <lastmod>$lastmod</lastmod>\n";
                }
                if (count($entry['urls']) > 1) {
                    foreach ($entry['urls'] as $lang => $alternate) {
                        $out .= '    <xhtml:link rel="alternate" hreflang="' . $esc($lang) . '" href="' . $esc(Uri::absolute($alternate)) . "\"/>\n";
                    }
                }
                $out .= "  </url>\n";
            }
        }
        return $out . "</urlset>\n";
    }

    /** robots.txt: the administrator is off limits; a hidden site (robots = noindex) is off limits entirely. */
    public static function robots(): string
    {
        $root = Uri::root();
        if (Config::get('robots', 'index') === 'noindex') {
            return "User-agent: *\nDisallow: $root\n";
        }
        return "User-agent: *\nDisallow: {$root}administrator/\nDisallow: {$root}installation/\n\n"
            . 'Sitemap: ' . Uri::absolute(self::url()) . "\n";
    }

    /** Root-relative URL of the sitemap (follows the URL rewriting setting). */
    public static function url(): string
    {
        return Uri::root() . (Config::get('sef_rewrite', true) ? '' : 'index.php/') . 'sitemap.xml';
    }
}
