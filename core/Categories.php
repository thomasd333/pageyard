<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Flat page categories with per-language title, alias, description and meta description.
 * Each category is also a list page at /<alias> (blog or list layout, options in params).
 */
final class Categories
{
    public const T = '#__category_translations';
    public const SORTABLE = ['title', 'state', 'pages', 'id'];

    /** Defaults of the list options. */
    public const LIST_DEFAULTS = [
        'layout'           => 'blog',      // blog | list
        'columns'          => 1,           // blog: cards per row (1-4)
        'intro_count'      => 6,           // blog: items with intro per page
        'link_count'       => 4,           // blog: further items shown as links only, per page
        'per_page'         => 20,          // list: rows per page
        'order'            => 'date_desc', // date_desc | date_asc | title_asc | title_desc | manual
        'show_date'        => true,
        'show_image'       => true,        // blog: thumbnails
        'show_description' => true,        // category description above the list
    ];
    public const ORDERS = ['date_desc', 'date_asc', 'title_asc', 'title_desc', 'manual'];

    public static function find(int $id): ?array
    {
        $row = Db::get()->fetchRow('SELECT * FROM #__categories WHERE id = ?', [$id]);
        if ($row !== null) {
            $row['params'] = self::params($row);
            $row['translations'] = Translations::load(self::T, 'category_id', $id);
        }
        return $row;
    }

    public static function blank(): array
    {
        return ['id' => 0, 'state' => 1, 'access' => 0, 'params' => self::LIST_DEFAULTS, 'translations' => [], 'created' => null, 'modified' => null];
    }

    /** Decoded list options with defaults. */
    public static function params(array $row): array
    {
        $params = is_array($row['params'] ?? null) ? $row['params'] : json_decode((string) ($row['params'] ?? ''), true);
        return array_intersect_key(is_array($params) ? $params : [], self::LIST_DEFAULTS) + self::LIST_DEFAULTS;
    }

    /**
     * Normalises list options from the form. Returns [params, errors].
     * @return array{array<string, mixed>, array<string, string>}
     */
    public static function listParams(array $in): array
    {
        $err = [];
        $p = self::LIST_DEFAULTS;
        $p['layout'] = ($in['layout'] ?? '') === 'list' ? 'list' : 'blog';
        $p['order'] = in_array($in['order'] ?? '', self::ORDERS, true) ? $in['order'] : 'date_desc';
        foreach (['columns' => [1, 4], 'intro_count' => [0, 100], 'link_count' => [0, 100], 'per_page' => [1, 100]] as $name => [$min, $max]) {
            $value = $in[$name] ?? self::LIST_DEFAULTS[$name];
            if (!is_numeric($value) || (int) $value < $min || (int) $value > $max) {
                $err[$name] = t('ERR_RANGE', $min, $max);
            } else {
                $p[$name] = (int) $value;
            }
        }
        if ($p['layout'] === 'blog' && $p['intro_count'] + $p['link_count'] === 0 && !isset($err['intro_count'])) {
            $err['intro_count'] = t('CATEGORIES_ERR_EMPTY_BLOG');
        }
        foreach (['show_date', 'show_image', 'show_description'] as $name) {
            $p[$name] = !empty($in[$name]);
        }
        return [$p, $err];
    }

    /**
     * id => title in $lang (falling back to any translation), sorted by title. For selects and filters.
     * @return array<int, string>
     */
    public static function options(string $lang): array
    {
        $rows = Db::get()->fetchAll('SELECT c.id, t.lang, t.title FROM #__categories c
            JOIN ' . self::T . ' t ON t.category_id = c.id ORDER BY c.id');
        $titles = [];
        foreach ($rows as $r) {
            if (!isset($titles[$r['id']]) || $r['lang'] === $lang) {
                $titles[(int) $r['id']] = $r['title'];
            }
        }
        asort($titles, SORT_NATURAL | SORT_FLAG_CASE);
        return $titles;
    }

    /**
     * @param array{search: string, state: string, sort: string, dir: string} $state
     * @return array{items: list<array>, total: int}
     */
    public static function list(array $state, string $lang, int $limit, int $offset): array
    {
        $db = Db::get();
        [$where, $params] = self::filters($state, $lang);
        $from = ' FROM #__categories c LEFT JOIN ' . self::T . ' t ON t.category_id = c.id AND t.lang = ?'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $params = [$lang, ...$params];

        $sort = ['title' => 'title', 'state' => 'c.state', 'pages' => 'pages', 'id' => 'c.id'][$state['sort']] ?? 'title';
        $dir = $state['dir'] === 'desc' ? 'DESC' : 'ASC';
        $items = $db->fetchAll('SELECT c.id, c.state, c.access, t.title, t.alias,
                (SELECT COUNT(*) FROM #__pages p WHERE p.category_id = c.id) AS pages' . $from
            . " ORDER BY $sort $dir, c.id" . ($limit > 0 ? " LIMIT $limit OFFSET $offset" : ''), $params);

        $langs = self::languagesOf(array_column($items, 'id'));
        foreach ($items as &$item) {
            $item['languages'] = $langs[$item['id']] ?? [];
        }
        return ['total' => (int) $db->fetchValue('SELECT COUNT(*)' . $from, $params), 'items' => $items];
    }

    /**
     * Validates and saves. Returns [id, errors, languages whose alias was made unique].
     * @param array{state: int, params?: array, translations: array<string, array<string, string>>} $data
     * @return array{int, array<string, string>, list<string>}
     */
    public static function save(int $id, array $data): array
    {
        [$rows, $errors, $renamed] = Translations::prepare(self::T, 'category_id', $id, $data['translations'], Config::languages(), Aliases::forCategory());
        [$params, $paramErrors] = self::listParams($data['params'] ?? self::LIST_DEFAULTS);
        $errors += $paramErrors;
        foreach ($rows as $lang => $r) {
            if (mb_strlen($r['meta_description'] ?? '') > 300) {
                $errors["$lang.meta_description"] = t('ERR_TOO_LONG', 300);
            }
        }
        if ($errors) {
            return [$id, $errors, []];
        }
        $rows = array_map(fn ($r) => [
            'title'            => $r['title'],
            'alias'            => $r['alias'],
            'description'      => trim($r['description'] ?? '') !== '' ? $r['description'] : null,
            'meta_description' => trim($r['meta_description'] ?? ''),
        ], $rows);
        $state = $data['state'] || Home::is('category', $id) ? 1 : 0;
        // The home page is for everyone; left out (e.g. a restore), the stored access stays.
        $access = isset($data['access']) ? (Home::is('category', $id) ? Access::ALL : Access::parse((int) $data['access'], [Access::ALL, Access::MEMBERS])) : null;

        $id = Db::get()->transaction(function (Db $db) use ($id, $state, $access, $params, $rows) {
            $paths = $id ? Redirects::categoryPaths($id) : [];
            $row = ['state' => $state, 'params' => json_encode($params), 'modified' => Db::now()];
            if ($access !== null) {
                $row['access'] = $access;
            }
            if ($id === 0) {
                $id = $db->insert('#__categories', $row + ['created' => $row['modified']]);
            } else {
                $db->update('#__categories', $row, ['id' => $id]);
            }
            Translations::save(self::T, 'category_id', $id, $rows);
            Redirects::moved('category', $id, $paths, Redirects::categoryPaths($id));
            return $id;
        });
        return [$id, [], $renamed];
    }

    /**
     * Publishes or unpublishes; the home category always stays published.
     * @param list<int> $ids
     */
    public static function setState(array $ids, int $state): int
    {
        if ($state === 0) {
            $ids = Home::without('category', $ids);
        }
        $count = 0;
        foreach ($ids as $id) {
            $count += Db::get()->update('#__categories', ['state' => $state, 'modified' => Db::now()], ['id' => $id]);
        }
        return $count;
    }

    /**
     * Deletes categories that have no pages (never the home category) and the menu items leading to them.
     * Returns [deleted count, titles of categories kept because they have pages].
     * @param list<int> $ids
     * @return array{int, list<string>}
     */
    public static function delete(array $ids, string $lang): array
    {
        $db = Db::get();
        $count = 0;
        $kept = [];
        $titles = self::options($lang);
        foreach (Home::without('category', $ids) as $id) {
            if ($db->fetchValue('SELECT 1 FROM #__pages WHERE category_id = ?', [$id])) {
                $kept[] = $titles[$id] ?? "#$id";
                continue;
            }
            $db->transaction(function (Db $db) use ($id, &$count) {
                Menus::deleteItems(array_map('intval', $db->fetchColumn('SELECT id FROM #__menu_items WHERE category_id = ?', [$id])));
                $db->delete(self::T, ['category_id' => $id]);
                $count += $db->delete('#__categories', ['id' => $id]);
            });
        }
        return [$count, $kept];
    }

    /** @return array{list<string>, list<mixed>} */
    private static function filters(array $state, string $lang): array
    {
        $where = [];
        $params = [];
        if ($state['search'] !== '') {
            if (preg_match('/^id:(\d+)$/', $state['search'], $m)) {
                $where[] = 'c.id = ?';
                $params[] = (int) $m[1];
            } else {
                // Any language's title or alias matches.
                $where[] = "c.id IN (SELECT category_id FROM " . self::T . " WHERE LOWER(title) LIKE ? ESCAPE '!' OR alias LIKE ? ESCAPE '!')";
                $like = '%' . Db::escapeLike(mb_strtolower($state['search'])) . '%';
                array_push($params, $like, $like);
            }
        }
        if ($state['state'] === '1' || $state['state'] === '0') {
            $where[] = 'c.state = ?';
            $params[] = (int) $state['state'];
        }
        Access::filter($state['access'] ?? '', 'c.access', $where, $params);
        return [$where, $params];
    }

    /** @return array<int, list<string>> category id => languages it has */
    private static function languagesOf(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::get()->fetchAll('SELECT category_id, lang FROM ' . self::T . " WHERE category_id IN ($marks)", $ids) as $r) {
            $out[(int) $r['category_id']][] = $r['lang'];
        }
        return $out;
    }
}
