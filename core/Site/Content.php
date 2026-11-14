<?php

namespace Pageyard\Site;

use Pageyard\Access;
use Pageyard\Categories;
use Pageyard\Config;
use Pageyard\Database\Db;
use Pageyard\Pages;
use Pageyard\Str;

defined('PAGEYARD') or exit;

/**
 * Read-only content queries for the site. A page is visible when it is published, its publish date
 * has passed, it has a translation in the language and its category (if any) is published, and the
 * visitor may see both (Access: some are for logged-in users only).
 */
final class Content
{
    public const TEASER_LENGTH = 200;

    /**
     * What a blog list shows of a page. A page with a "Read more" line: the text before it. Without one, the
     * blog_text option decides: the whole text, or its start (a short text without images stays whole).
     * @return array{html: string, more: bool} HTML, and whether the page has more than that
     */
    public static function teaser(array $page): array
    {
        if ($page['intro'] !== null) {
            return ['html' => Html::content($page['intro']), 'more' => $page['body'] !== null];
        }
        if ($page['body'] === null) {
            return ['html' => '', 'more' => false];
        }
        $text = (string) preg_replace('~\{script\s[^{}]*\}~i', '', $page['body']);
        $short = $text === $page['body'] && !preg_match('~<(?:img|iframe|video|audio|object|embed|table)\b~i', $text)
            && mb_strlen(Str::excerpt($text, PHP_INT_MAX)) <= self::TEASER_LENGTH;
        if (Config::get('blog_text', 'full') !== 'excerpt' || $short) {
            return ['html' => Html::content($page['body']), 'more' => false];
        }
        $excerpt = Str::excerpt($text, self::TEASER_LENGTH);
        return ['html' => $excerpt !== '' ? '<p>' . e($excerpt) . '</p>' : '', 'more' => true];
    }

    /** Condition for a visible page (p) with its category (c); takes the current time as its parameter. */
    private static function visible(): string
    {
        return 'p.state = 1 AND p.publish_up <= ? AND ' . Access::sql('p.access')
            . ' AND (p.category_id IS NULL OR (c.state = 1 AND ' . Access::sql('c.access') . '))';
    }
    private const SELECT = 'SELECT p.id, p.category_id, p.image, p.publish_up, p.modified,
            t.title, t.alias, t.intro, t.body, t.meta_description
        FROM #__pages p
        JOIN ' . Pages::T . ' t ON t.page_id = p.id AND t.lang = ?
        LEFT JOIN #__categories c ON c.id = p.category_id';

    public static function page(int $id, string $lang): ?array
    {
        return Db::get()->fetchRow(self::SELECT . ' WHERE p.id = ? AND ' . self::visible(), [$lang, $id, Db::now()]);
    }

    /**
     * A page in $lang whatever its state (administrator preview), with state, category_state for the notice.
     */
    public static function pageAny(int $id, string $lang): ?array
    {
        return Db::get()->fetchRow('SELECT p.id, p.category_id, p.image, p.publish_up, p.modified,
                t.title, t.alias, t.intro, t.body, t.meta_description, p.state, c.state AS category_state,
                p.access, c.access AS category_access
            FROM #__pages p
            JOIN ' . Pages::T . ' t ON t.page_id = p.id AND t.lang = ?
            LEFT JOIN #__categories c ON c.id = p.category_id
            WHERE p.id = ?', [$lang, $id]);
    }

    /** id, category_id, title and alias of a visible page (no texts), e.g. for menus. */
    public static function pageLink(int $id, string $lang): ?array
    {
        return Db::get()->fetchRow('SELECT p.id, p.category_id, t.title, t.alias FROM #__pages p
            JOIN ' . Pages::T . ' t ON t.page_id = p.id AND t.lang = ?
            LEFT JOIN #__categories c ON c.id = p.category_id
            WHERE p.id = ? AND ' . self::visible(), [$lang, $id, Db::now()]);
    }

    /** Languages in which a page is visible: lang => alias. */
    public static function pageLanguages(int $id): array
    {
        $rows = Db::get()->fetchAll('SELECT t.lang, t.alias FROM ' . Pages::T . ' t
            JOIN #__pages p ON p.id = t.page_id LEFT JOIN #__categories c ON c.id = p.category_id
            WHERE t.page_id = ? AND ' . self::visible(), [$id, Db::now()]);
        return array_column($rows, 'alias', 'lang');
    }

    /**
     * Every page visible to everyone, in every language (for the sitemap): id, category_id, lang, alias, publish_up, modified.
     * @return list<array>
     */
    public static function visibleTranslations(): array
    {
        return Access::as(false, fn () => Db::get()->fetchAll('SELECT p.id, p.category_id, t.lang, t.alias, p.publish_up, p.modified FROM #__pages p
            JOIN ' . Pages::T . ' t ON t.page_id = p.id
            LEFT JOIN #__categories c ON c.id = p.category_id
            WHERE ' . self::visible() . ' ORDER BY p.publish_up DESC, p.id DESC', [Db::now()]));
    }

    /** Published category in $lang the visitor may see (title, alias, description, meta_description, params JSON) or null. */
    public static function category(int $id, string $lang): ?array
    {
        return Db::get()->fetchRow('SELECT c.id, c.params, t.title, t.alias, t.description, t.meta_description FROM #__categories c
            JOIN ' . Categories::T . ' t ON t.category_id = c.id AND t.lang = ?
            WHERE c.id = ? AND c.state = 1 AND ' . Access::sql('c.access'), [$lang, $id]);
    }

    /**
     * Visible pages of a category in $lang.
     * @return array{items: list<array>, total: int}
     */
    public static function categoryPages(int $categoryId, string $lang, string $order, int $limit, int $offset): array
    {
        $db = Db::get();
        $where = ' WHERE p.category_id = ? AND ' . self::visible();
        $params = [$lang, $categoryId, Db::now()];
        $orderBy = match ($order) {
            'date_asc'   => 'p.publish_up ASC, p.id ASC',
            'title_asc'  => 't.title ASC, p.id ASC',
            'title_desc' => 't.title DESC, p.id DESC',
            'manual'     => 'p.ordering ASC, p.id ASC',
            default      => 'p.publish_up DESC, p.id DESC',
        };
        $total = (int) $db->fetchValue('SELECT COUNT(*) FROM #__pages p
            JOIN ' . Pages::T . ' t ON t.page_id = p.id AND t.lang = ?
            LEFT JOIN #__categories c ON c.id = p.category_id' . $where, $params);
        $items = $limit > 0
            ? $db->fetchAll(self::SELECT . $where . " ORDER BY $orderBy LIMIT $limit OFFSET $offset", $params)
            : [];
        return ['items' => $items, 'total' => $total];
    }
}
