<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/** Pages (Joomla "articles"): shared fields in #__pages, texts per language in #__page_translations. */
final class Pages
{
    /** The line in the editor's text that ends what lists show. Never stored: see splitText(). */
    public const READ_MORE = '<hr class="py-readmore">';

    public const T = '#__page_translations';
    public const SORTABLE = ['title', 'category', 'state', 'publish_up', 'modified', 'id', 'ordering'];

    public static function find(int $id): ?array
    {
        $row = Db::get()->fetchRow('SELECT p.*, cu.name AS created_by_name, mu.name AS modified_by_name
            FROM #__pages p
            LEFT JOIN #__users cu ON cu.id = p.created_by
            LEFT JOIN #__users mu ON mu.id = p.modified_by
            WHERE p.id = ?', [$id]);
        if ($row !== null) {
            $row['translations'] = Translations::load(self::T, 'page_id', $id);
        }
        return $row;
    }

    /**
     * id => title in $lang for selects (unpublished pages marked), sorted by title.
     * @return array<int, string>
     */
    public static function options(string $lang): array
    {
        $rows = Db::get()->fetchAll('SELECT p.id, p.state, t.title FROM #__pages p
            JOIN ' . self::T . ' t ON t.page_id = p.id AND t.lang = ? ORDER BY t.title, p.id', [$lang]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r['title'] . ($r['state'] ? '' : ' (' . t('STATE_UNPUBLISHED') . ')');
        }
        return $out;
    }

    /** Defaults for the "new page" form. */
    public static function blank(): array
    {
        return ['id' => 0, 'category_id' => null, 'state' => 1, 'access' => 0, 'image' => '', 'publish_up' => Db::now(),
            'created' => null, 'modified' => null, 'created_by_name' => null, 'modified_by_name' => null, 'translations' => []];
    }

    /**
     * Titles are shown in $lang (the default content language, which every page has).
     *
     * @param array{search: string, state: string, category: string, sort: string, dir: string} $state
     * @return array{items: list<array>, total: int}
     */
    public static function list(array $state, string $lang, int $limit, int $offset): array
    {
        $db = Db::get();
        $where = [];
        $params = [$lang, $lang];
        if ($state['search'] !== '') {
            if (preg_match('/^id:(\d+)$/', $state['search'], $m)) {
                $where[] = 'p.id = ?';
                $params[] = (int) $m[1];
            } else {
                $where[] = 'p.id IN (SELECT page_id FROM ' . self::T . " WHERE LOWER(title) LIKE ? ESCAPE '!' OR alias LIKE ? ESCAPE '!')";
                $like = '%' . Db::escapeLike(mb_strtolower($state['search'])) . '%';
                array_push($params, $like, $like);
            }
        }
        if ($state['state'] === '1' || $state['state'] === '0') {
            $where[] = 'p.state = ?';
            $params[] = (int) $state['state'];
        }
        Access::filter($state['access'] ?? '', 'p.access', $where, $params);
        if ($state['category'] === 'none') {
            $where[] = 'p.category_id IS NULL';
        } elseif (ctype_digit($state['category'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int) $state['category'];
        }

        $from = ' FROM #__pages p
            LEFT JOIN ' . self::T . ' t ON t.page_id = p.id AND t.lang = ?
            LEFT JOIN ' . Categories::T . ' ct ON ct.category_id = p.category_id AND ct.lang = ?
            LEFT JOIN #__users u ON u.id = p.created_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $sort = [
            'title' => 't.title', 'category' => 'ct.title', 'state' => 'p.state',
            'publish_up' => 'p.publish_up', 'modified' => 'p.modified', 'id' => 'p.id', 'ordering' => 'p.ordering',
        ][$state['sort']] ?? 'p.publish_up';
        $dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';

        $items = $db->fetchAll("SELECT p.id, p.state, p.access, p.image, p.publish_up, p.modified, p.category_id, p.ordering,
                t.title, t.alias, ct.title AS category, u.name AS author$from
            ORDER BY $sort $dir, p.id DESC" . ($limit > 0 ? " LIMIT $limit OFFSET $offset" : ''), $params);

        if ($items) {
            $ids = array_column($items, 'id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $langs = [];
            foreach ($db->fetchAll('SELECT page_id, lang FROM ' . self::T . " WHERE page_id IN ($marks)", $ids) as $r) {
                $langs[(int) $r['page_id']][] = $r['lang'];
            }
            foreach ($items as &$item) {
                $item['languages'] = $langs[$item['id']] ?? [];
            }
        }
        return ['total' => (int) $db->fetchValue('SELECT COUNT(*)' . $from, $params), 'items' => $items];
    }

    /**
     * Validates and saves. Returns [id, errors, languages whose alias was made unique].
     * Errors use "field" for shared fields and "lang.field" for translations.
     *
     * @param array{state: int, category_id: int, image: string, publish_up: string,
     *              translations: array<string, array<string, string>>} $data
     * @param string $reason what replaces the old version, for the history (save | restore)
     * @param array<string, mixed> $insert columns of a new row: id, created, created_by (a deleted page coming back)
     * @return array{int, array<string, string>, list<string>}
     */
    public static function save(int $id, array $data, int $userId, string $reason = 'save', array $insert = []): array
    {
        [$rows, $errors, $renamed] = Translations::prepare(self::T, 'page_id', $id, $data['translations'], Config::languages(), Aliases::forPage());

        $categoryId = $data['category_id'] > 0 ? $data['category_id'] : null;
        if ($categoryId !== null && !Db::get()->fetchValue('SELECT 1 FROM #__categories WHERE id = ?', [$categoryId])) {
            $errors['category_id'] = t('ERR_REQUIRED');
        }
        $image = trim($data['image']);
        if ($image !== '' && !Media::isImagePath($image)) {
            $errors['image'] = t('PAGES_ERR_IMAGE');
        }
        $publishUp = self::parseLocalDate($data['publish_up']);
        if ($publishUp === null) {
            $errors['publish_up'] = t('PAGES_ERR_DATE');
        }
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
            'intro'            => self::nullIfEmpty($r['intro'] ?? ''),
            'body'             => self::nullIfEmpty($r['body'] ?? ''),
            'meta_description' => trim($r['meta_description'] ?? ''),
        ], $rows);

        $id = Db::get()->transaction(function (Db $db) use ($id, $data, $rows, $categoryId, $image, $publishUp, $userId, $reason, $insert) {
            $before = $id ? self::revision($id) : null;
            $paths = $id ? Redirects::pagePaths($id) : [];
            $row = [
                'category_id' => $categoryId,
                'state'       => $data['state'] ? 1 : 0,
                'image'       => $image,
                'publish_up'  => $publishUp,
                'modified'    => Db::now(),
                'modified_by' => $userId ?: null,
            ];
            // The home page is for everyone; left out (moves, restores), the stored access stays.
            if (isset($data['access'])) {
                $row['access'] = $id && Home::is('page', $id) ? Access::ALL : Access::parse((int) $data['access'], [Access::ALL, Access::MEMBERS]);
            }
            // In a category for the first time: at the end of its manual order.
            if ($id === 0 || ($before['content']['category_id'] ?? null) !== $categoryId) {
                $row['ordering'] = self::lastPlace($categoryId);
            }
            if ($id === 0) {
                $newId = $db->insert('#__pages', $row + $insert + ['created' => $row['modified'], 'created_by' => $userId ?: null]);
                $id = (int) ($insert['id'] ?? $newId);
            } else {
                $db->update('#__pages', $row, ['id' => $id]);
            }
            Translations::save(self::T, 'page_id', $id, $rows);
            Redirects::moved('page', $id, $paths, Redirects::pagePaths($id));
            Revisions::saved('page', $id, $before, self::revision($id), $reason);
            return $id;
        });
        return [$id, [], $renamed];
    }

    /**
     * Moves pages to a category ($categoryId null = none). Their old addresses redirect, and the
     * change is in their history like any other. Returns how many moved.
     * @param list<int> $ids
     */
    public static function moveTo(array $ids, ?int $categoryId, int $userId): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $count += Db::get()->transaction(function (Db $db) use ($id, $categoryId, $userId) {
                $before = self::revision($id);
                if ($before === null || $before['content']['category_id'] === $categoryId) {
                    return 0;
                }
                $paths = Redirects::pagePaths($id);
                $db->update('#__pages', ['category_id' => $categoryId, 'ordering' => self::lastPlace($categoryId),
                    'modified' => Db::now(), 'modified_by' => $userId ?: null], ['id' => $id]);
                Redirects::moved('page', $id, $paths, Redirects::pagePaths($id));
                Revisions::saved('page', $id, $before, self::revision($id), 'save');
                return 1;
            });
        }
        return $count;
    }

    /** One place up (-1) or down (+1) in its category's manual order. */
    public static function moveInOrder(int $id, int $direction): bool
    {
        return Ordering::move('#__pages', $id, $direction, ['category_id']);
    }

    private static function lastPlace(?int $categoryId): int
    {
        return $categoryId === null ? 0 : Ordering::next('#__pages', ['category_id' => $categoryId]);
    }

    /**
     * Publishes or unpublishes; the home page always stays published.
     * @param list<int> $ids
     */
    public static function setState(array $ids, int $state, int $userId): int
    {
        if ($state === 0) {
            $ids = Home::without('page', $ids);
        }
        $count = 0;
        foreach ($ids as $id) {
            $count += Db::get()->update('#__pages', ['state' => $state, 'modified' => Db::now(), 'modified_by' => $userId ?: null], ['id' => $id]);
        }
        return $count;
    }

    /**
     * Deletes pages (not the home page) and the menu items leading to them.
     * @param list<int> $ids
     */
    public static function delete(array $ids): int
    {
        $count = 0;
        foreach (Home::without('page', $ids) as $id) {
            $count += Db::get()->transaction(function (Db $db) use ($id) {
                $before = self::revision($id);
                if ($before !== null) {
                    Revisions::record('page', $id, $before, 'delete');
                }
                Menus::deleteItems(array_map('intval', $db->fetchColumn('SELECT id FROM #__menu_items WHERE page_id = ?', [$id])));
                $db->delete(self::T, ['page_id' => $id]);
                return $db->delete('#__pages', ['id' => $id]);
            });
        }
        if ($count) {
            Log::info("Pages deleted: $count");
        }
        return $count;
    }

    /**
     * The page's current version for the history. "content" decides whether a save changed anything
     * and is what a restore brings back; "extra" is only used when a deleted page returns.
     */
    public static function revision(int $id): ?array
    {
        $page = self::find($id);
        if ($page === null) {
            return null;
        }
        $translations = [];
        foreach ($page['translations'] as $lang => $tr) {
            $translations[$lang] = ['title' => $tr['title'], 'alias' => $tr['alias'], 'intro' => $tr['intro'],
                'body' => $tr['body'], 'meta_description' => $tr['meta_description']];
        }
        ksort($translations);
        return [
            'title'   => $page['translations'][Config::languages()[0]]['title'] ?? (reset($translations)['title'] ?? ''),
            'date'    => $page['modified'],
            'author'  => $page['modified_by_name'],
            'content' => [
                'category_id'  => $page['category_id'] !== null ? (int) $page['category_id'] : null,
                'image'        => (string) $page['image'],
                'translations' => $translations,
            ],
            'extra'   => ['publish_up' => $page['publish_up'], 'created' => $page['created'],
                'created_by' => $page['created_by'] !== null ? (int) $page['created_by'] : null],
        ];
    }

    /**
     * Brings back a version from the history. Status and publish date stay as they are; an alias or
     * category that is no longer available stays as it is too. A deleted page returns under its
     * old id (AUTOINCREMENT ids aren't reused), unpublished.
     * @return array{int, array<string, string>, list<string>}
     */
    public static function restore(int $id, array $content, array $extra, int $userId): array
    {
        $current = self::find($id);
        $translations = [];
        foreach ((array) ($content['translations'] ?? []) as $lang => $tr) {
            $alias = (string) ($tr['alias'] ?? '');
            if ($alias !== '' && Translations::uniqueAlias(self::T, 'page_id', $id, (string) $lang, $alias, Aliases::forPage()) !== $alias) {
                $alias = $current['translations'][$lang]['alias'] ?? $alias;
            }
            $translations[$lang] = array_merge((array) $tr, ['alias' => $alias]);
        }
        $category = (int) ($content['category_id'] ?? 0);
        if ($category > 0 && !Db::get()->fetchValue('SELECT 1 FROM #__categories WHERE id = ?', [$category])) {
            $category = (int) ($current['category_id'] ?? 0);
        }
        $data = [
            'state'        => $current !== null ? (int) $current['state'] : 0,
            'category_id'  => $category,
            'image'        => (string) ($content['image'] ?? ''),
            'publish_up'   => py_date((string) ($current['publish_up'] ?? $extra['publish_up'] ?? Db::now()), 'Y-m-d\TH:i:s'),
            'translations' => $translations,
        ];
        if ($current !== null) {
            return self::save($id, $data, $userId, 'restore');
        }
        return self::save(0, $data, $userId, 'restore', ['id' => $id, 'created' => $extra['created'] ?? Db::now(), 'created_by' => $extra['created_by'] ?? null]);
    }

    /** "2026-11-03T14:30" in the site time zone -> UTC column value, or null if invalid. */
    public static function parseLocalDate(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone(date_default_timezone_get()));
            if ($d !== false && $d->format($format) === $value) {
                return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    /** TinyMCE leaves "<p>&nbsp;</p>" or similar in an emptied editor. */
    /**
     * Splits the editor's text at its "Read more" line into [intro, body]. Only a line outside any other
     * element counts (cutting inside a table would break both parts); further lines are dropped. Without a
     * usable line, or with nothing on one side of it, everything is the body.
     * @return array{string, string}
     */
    public static function splitText(string $html): array
    {
        $line = '~<hr\b[^>]*\bpy-readmore\b[^>]*>~i';
        preg_match_all($line, $html, $found, PREG_OFFSET_CAPTURE);
        $at = null;
        foreach ($found[0] as [$marker, $offset]) {
            if (self::isTopLevel(substr($html, 0, $offset))) {
                $at = [$offset, $offset + strlen($marker)];
                break;
            }
        }
        $clean = static fn (string $part): string => trim((string) preg_replace($line, '', $part));
        if ($at === null) {
            return ['', $clean($html)];
        }
        // TinyMCE leaves an empty paragraph next to a line put at the end of a paragraph.
        $blank = '<p[^>]*>(?:\s|&nbsp;|\x{a0})*</p>\s*';
        $intro = (string) preg_replace("~(?:$blank)+$~iu", '', $clean(substr($html, 0, $at[0])));
        $body = (string) preg_replace("~^(?:$blank)+~iu", '', $clean(substr($html, $at[1])));
        if (self::nullIfEmpty($intro) === null) {
            return ['', $body];
        }
        return self::nullIfEmpty($body) === null ? ['', $intro] : [$intro, $body];
    }

    /** The text for the editor: splitText() the other way round. */
    public static function joinText(?string $intro, ?string $body): string
    {
        $parts = array_filter([(string) $intro, (string) $body], static fn (string $part): bool => $part !== '');
        return implode("\n" . self::READ_MORE . "\n", $parts);
    }

    /** Whether all elements opened in this HTML are closed again. */
    private static function isTopLevel(string $html): bool
    {
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
        preg_match_all('~<(/?)([a-z][a-z0-9-]*)\b[^>]*?(/?)>~i', $html, $tags, PREG_SET_ORDER);
        $depth = 0;
        foreach ($tags as [, $closing, $name, $selfClosed]) {
            if ($selfClosed === '' && !in_array(strtolower($name), $void, true)) {
                $depth += $closing === '' ? 1 : -1;
            }
        }
        return $depth === 0;
    }

    private static function nullIfEmpty(string $html): ?string
    {
        $text = trim(str_replace(["\u{a0}", '&nbsp;'], ' ', strip_tags($html, '<img><iframe><video><audio><object><embed><hr><table>')));
        return $text === '' ? null : $html;
    }
}
