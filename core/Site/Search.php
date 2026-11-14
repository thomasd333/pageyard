<?php

namespace Pageyard\Site;

use Pageyard\Access;
use Pageyard\Categories;
use Pageyard\Database\Db;
use Pageyard\Pages;

defined('PAGEYARD') or exit;

/**
 * Site search over what visitors can see, in one language: every word must occur in the title or text.
 * The database finds candidates; PHP then looks at the plain text, because LIKE also matches
 * inside HTML tags and {script} parameters, and puts title matches first.
 */
final class Search
{
    public const PATH = 'search';
    public const PER_PAGE = 10;
    public const MAX_QUERY = 100;
    private const MAX_WORDS = 8;
    private const CANDIDATES = 500;

    /** @return list<string> lowercase words of at least 2 characters */
    public static function words(string $query): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, fn (string $word): bool => mb_strlen($word) >= 2);
        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /** @return list<array{title: string, url: string, snippet: string}> best first */
    public static function run(string $query, string $lang): array
    {
        $words = self::words($query);
        if (!$words) {
            return [];
        }
        $db = Db::get();
        $found = [];

        [$where, $params] = self::where($words, ['t.title', 't.intro', 't.body']);
        $pages = $db->fetchAll('SELECT p.id, p.category_id, p.publish_up, t.title, t.alias, t.intro, t.body FROM #__pages p
            JOIN ' . Pages::T . ' t ON t.page_id = p.id AND t.lang = ?
            LEFT JOIN #__categories c ON c.id = p.category_id
            WHERE p.state = 1 AND p.publish_up <= ? AND ' . Access::sql('p.access') . '
                AND (p.category_id IS NULL OR (c.state = 1 AND ' . Access::sql('c.access') . ')) AND ' . $where
            . ' ORDER BY p.publish_up DESC, p.id DESC LIMIT ' . self::CANDIDATES, [$lang, Db::now(), ...$params]);
        foreach ($pages as $page) {
            $url = Router::pageUrl((int) $page['id'], $page['category_id'] !== null ? (int) $page['category_id'] : null, $page['alias'], $lang);
            $text = self::plain(($page['intro'] ?? '') . ' ' . ($page['body'] ?? ''));
            $result = $url !== null ? self::result($words, $page['title'], $text, $url, $page['publish_up']) : null;
            if ($result !== null) {
                $found[] = $result;
            }
        }

        [$where, $params] = self::where($words, ['t.title', 't.description']);
        $categories = $db->fetchAll('SELECT c.id, t.title, t.description FROM #__categories c
            JOIN ' . Categories::T . ' t ON t.category_id = c.id AND t.lang = ?
            WHERE c.state = 1 AND ' . Access::sql('c.access') . ' AND ' . $where . ' LIMIT ' . self::CANDIDATES, [$lang, ...$params]);
        foreach ($categories as $category) {
            $url = Router::categoryUrl((int) $category['id'], $lang);
            $result = $url !== null ? self::result($words, $category['title'], self::plain($category['description'] ?? ''), $url, '') : null;
            if ($result !== null) {
                $found[] = $result;
            }
        }

        usort($found, fn ($a, $b) => [$b['hits'], $b['date']] <=> [$a['hits'], $a['date']]);
        return array_map(fn ($r) => ['title' => $r['title'], 'url' => $r['url'], 'snippet' => $r['snippet']], $found);
    }

    /**
     * Every word in at least one of the columns.
     * @param list<string> $words
     * @param list<string> $columns
     * @return array{string, list<string>}
     */
    private static function where(array $words, array $columns): array
    {
        $parts = [];
        $params = [];
        foreach ($words as $word) {
            $like = '%' . Db::escapeLike($word) . '%';
            $any = [];
            foreach ($columns as $column) {
                $any[] = "LOWER($column) LIKE ? ESCAPE '!'";
                $params[] = $like;
            }
            $parts[] = '(' . implode(' OR ', $any) . ')';
        }
        return [implode(' AND ', $parts), $params];
    }

    /** Text a visitor reads: no tags, entities or {script} tags. */
    private static function plain(string $html): string
    {
        $html = (string) preg_replace('~\{script\s[^{}]*\}~i', ' ', str_replace(['</p>', '<br', '</li>', '</h'], [' </p>', ' <br', ' </li>', ' </h'], $html));
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** The result if all words really occur; hits = how many of them are in the title. */
    private static function result(array $words, string $title, string $text, string $url, string $date): ?array
    {
        $hits = 0;
        foreach ($words as $word) {
            $inTitle = mb_stripos($title, $word) !== false;
            if (!$inTitle && mb_stripos($text, $word) === false) {
                return null;
            }
            $hits += $inTitle ? 1 : 0;
        }
        return ['title' => $title, 'url' => $url, 'date' => $date, 'hits' => $hits, 'snippet' => self::snippet($text, $words)];
    }

    /** Escaped HTML: a piece of the text around the first word found, with the words marked. */
    private static function snippet(string $text, array $words, int $length = 220): string
    {
        $first = null;
        foreach ($words as $word) {
            $at = mb_stripos($text, $word);
            if ($at !== false && ($first === null || $at < $first)) {
                $first = $at;
            }
        }
        $start = max(0, (int) $first - 60);
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space < (int) $first ? $space + 1 : $start;
        }
        $piece = mb_substr($text, $start, $length);
        if ($start + $length < mb_strlen($text)) {
            $space = mb_strrpos($piece, ' ');
            $piece = ($space !== false && $space > $length / 2 ? mb_substr($piece, 0, $space) : $piece) . '…';
        }
        $piece = ($start > 0 ? '…' : '') . $piece;
        $pattern = '~' . implode('|', array_map(fn ($w) => preg_quote(e($w), '~'), $words)) . '~iu';
        return (string) preg_replace($pattern, '<mark>$0</mark>', e($piece));
    }

    /** The search box: for blocks and the results page. */
    public static function form(string $lang, string $query = ''): string
    {
        return '<form class="py-search" role="search" method="get" action="' . e(Router::url(self::PATH, $lang)) . '"><div class="input-group">'
            . '<input class="form-control" type="search" name="q" value="' . e($query) . '" maxlength="' . self::MAX_QUERY
            . '" placeholder="' . e(t('SEARCH_PLACEHOLDER')) . '" aria-label="' . e(t('SEARCH_PLACEHOLDER')) . '">'
            . '<button class="btn btn-primary" type="submit">' . e(t('SEARCH_BUTTON')) . '</button></div></form>';
    }
}
