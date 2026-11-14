<?php

namespace Pageyard;

use Pageyard\Database\Db;
use Pageyard\Site\Router;

defined('PAGEYARD') or exit;

/**
 * Old addresses leading somewhere else (301). Renaming or moving a page or category records its old
 * address by itself; others are added by hand, e.g. the addresses of a previous site.
 *
 * Targets are the item, not its address, so a later rename can't make a chain and a deleted page that
 * comes back works again. Content always wins: a redirect is only looked at when nothing else
 * answers the path.
 */
final class Redirects
{
    public const SORTABLE = ['path', 'hits', 'last_hit', 'created', 'id'];
    private const MAX_PATH = 255;
    private const MAX_URL = 500;

    public static function find(string $lang, string $path): ?array
    {
        return Db::get()->fetchRow('SELECT * FROM #__redirects WHERE lang = ? AND path = ?', [$lang, $path]);
    }

    public static function get(int $id): ?array
    {
        return Db::get()->fetchRow('SELECT * FROM #__redirects WHERE id = ?', [$id]);
    }

    /** Defaults for the "new redirect" form. */
    public static function blank(): array
    {
        return ['id' => 0, 'from' => '', 'target_type' => 'page', 'target_id' => 0, 'url' => ''];
    }

    /** Current path of a page per language: lang => path. A category without that language gives no path. */
    public static function pagePaths(int $id): array
    {
        $paths = [];
        $rows = Db::get()->fetchAll('SELECT t.lang, t.alias, p.category_id, ct.alias AS category_alias FROM #__pages p
            JOIN ' . Pages::T . ' t ON t.page_id = p.id
            LEFT JOIN ' . Categories::T . ' ct ON ct.category_id = p.category_id AND ct.lang = t.lang
            WHERE p.id = ?', [$id]);
        foreach ($rows as $r) {
            if ($r['category_id'] === null) {
                $paths[$r['lang']] = $r['alias'];
            } elseif ($r['category_alias'] !== null) {
                $paths[$r['lang']] = $r['category_alias'] . '/' . $r['alias'];
            }
        }
        return $paths;
    }

    /** Current path of a category per language: lang => path. */
    public static function categoryPaths(int $id): array
    {
        return array_column(Db::get()->fetchAll('SELECT lang, alias FROM ' . Categories::T . ' WHERE category_id = ?', [$id]), 'alias', 'lang');
    }

    /**
     * Called after an item is saved, with its paths before and after: addresses it left now redirect to it,
     * and redirects sitting on its present addresses are gone (it answers them itself).
     * A language the item no longer has gets no redirect: that text doesn't exist any more.
     *
     * @param array<string, string> $before lang => path
     * @param array<string, string> $after  lang => path
     */
    public static function moved(string $type, int $id, array $before, array $after): void
    {
        $db = Db::get();
        foreach ($after as $lang => $path) {
            $db->delete('#__redirects', ['lang' => $lang, 'path' => $path]);
            $old = $before[$lang] ?? null;
            if ($old === null || $old === $path || strlen($old) > self::MAX_PATH) {
                continue;
            }
            $db->delete('#__redirects', ['lang' => $lang, 'path' => $old]);
            $db->insert('#__redirects', ['lang' => $lang, 'path' => $old, 'target_type' => $type, 'target_id' => $id,
                'url' => '', 'auto' => 1, 'hits' => 0, 'created' => Db::now()]);
        }
    }

    /** Counts a use. A problem here must not stop the visitor. */
    public static function hit(int $id): void
    {
        try {
            Db::get()->query('UPDATE #__redirects SET hits = hits + 1, last_hit = ? WHERE id = ?', [Db::now(), $id]);
        } catch (\Throwable $e) {
            Log::error('Redirect hit not counted: ' . $e->getMessage());
        }
    }

    /**
     * Splits an address as typed or pasted (full URL, /CMS/path/, path) into language and path.
     * @return array{string, string} [lang, path]
     */
    public static function split(string $address): array
    {
        $path = self::cleanPath($address);
        $segments = explode('/', $path);
        if (count(Config::languages()) > 1 && in_array($segments[0], Config::languages(), true)) {
            $lang = array_shift($segments);
            return [$lang, implode('/', $segments)];
        }
        return [(string) Config::get('language'), $path];
    }

    /** Path from the site root: no scheme, host, query, index.php or surrounding slashes. */
    private static function cleanPath(string $address): string
    {
        $address = trim($address);
        if (preg_match('~^https?://~i', $address)) {
            $address = (string) parse_url($address, PHP_URL_PATH);
        }
        $address = rawurldecode((string) preg_replace('/[?#].*$/s', '', $address));
        $root = Uri::root();
        if ($root !== '/' && str_starts_with($address, $root)) {
            $address = substr($address, strlen($root));
        }
        $address = ltrim($address, '/');
        if ($address === 'index.php') {
            return '';
        }
        if (str_starts_with($address, 'index.php/')) {
            $address = substr($address, 10);
        }
        return trim($address, '/');
    }

    /**
     * Validates and saves a redirect made by hand.
     * @param array{from: string, target_type: string, target_id: int, url: string} $data
     * @return array{int, array<string, string>}
     */
    public static function save(int $id, array $data): array
    {
        $db = Db::get();
        $errors = [];
        [$lang, $path] = self::split($data['from']);
        $prefix = $lang === (string) Config::get('language') ? '' : "$lang/";
        if ($path === '') {
            $errors['from'] = t('ERR_REQUIRED');
        } elseif (mb_strlen($path) > self::MAX_PATH) {
            $errors['from'] = t('ERR_TOO_LONG', self::MAX_PATH);
        } elseif (Aliases::isReserved(explode('/', $path)[0]) || in_array($path, ['sitemap.xml', 'robots.txt'], true)) {
            $errors['from'] = t('REDIRECTS_ERR_RESERVED');
        } elseif (Router::resolve($prefix . $path, false)['status'] !== 404) {
            $errors['from'] = t('REDIRECTS_ERR_LIVE');
        } else {
            $other = self::find($lang, $path);
            if ($other !== null && (int) $other['id'] !== $id) {
                $errors['from'] = t('REDIRECTS_ERR_EXISTS');
            }
        }

        $type = $data['target_type'];
        $targetId = 0;
        $url = '';
        if ($type === 'page' || $type === 'category') {
            $targetId = $data['target_id'];
            $table = $type === 'page' ? '#__pages' : '#__categories';
            if ($targetId <= 0 || !$db->fetchValue("SELECT 1 FROM $table WHERE id = ?", [$targetId])) {
                $errors[$type . '_id'] = t('ERR_REQUIRED');
            }
        } else {
            $type = 'url';
            [$url, $error] = self::cleanTarget($data['url']);
            if ($error !== null) {
                $errors['url'] = $error;
            } elseif (!isset($errors['from']) && self::loops($lang, $path, $url)) {
                $errors['url'] = t('REDIRECTS_ERR_LOOP');
            }
        }
        if ($errors) {
            return [$id, $errors];
        }

        $row = ['lang' => $lang, 'path' => $path, 'target_type' => $type, 'target_id' => $targetId, 'url' => $url, 'auto' => 0];
        if ($id === 0) {
            $id = $db->insert('#__redirects', $row + ['hits' => 0, 'created' => Db::now()]);
        } else {
            $db->update('#__redirects', $row, ['id' => $id]);
        }
        return [$id, []];
    }

    /** @return array{string, ?string} [stored target, error] */
    private static function cleanTarget(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['', t('ERR_REQUIRED')];
        }
        if (mb_strlen($url) > self::MAX_URL) {
            return ['', t('ERR_TOO_LONG', self::MAX_URL)];
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return ['', t('REDIRECTS_ERR_URL')];
        }
        if (preg_match('~^https?://[^/\s]+\S*$~i', $url)) {
            return [$url, null];
        }
        // Another scheme (javascript:, mailto:...), a broken full address, or a protocol-relative one.
        if (str_starts_with($url, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            return ['', t('REDIRECTS_ERR_URL')];
        }
        $query = str_contains($url, '?') ? '?' . explode('?', $url, 2)[1] : '';
        return [self::cleanPath($url) . $query, null];
    }

    /** True when following address targets from $target comes back to lang + path. */
    private static function loops(string $lang, string $path, string $target): bool
    {
        for ($i = 0; $i < 10; $i++) {
            if (preg_match('~^https?://~i', $target)) {
                return false;
            }
            [$targetLang, $targetPath] = self::split($target);
            if ($targetLang === $lang && $targetPath === $path) {
                return true;
            }
            $next = self::find($targetLang, $targetPath);
            if ($next === null || $next['target_type'] !== 'url') {
                return false;
            }
            $target = $next['url'];
        }
        return true;
    }

    /** Where a stored address target leads. */
    public static function targetAddress(string $url): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        return Router::url($encoded, (string) Config::get('language')) . ($query !== null ? "?$query" : '');
    }

    /**
     * @param array{search: string, kind: string, sort: string, dir: string} $state
     * @return array{items: list<array>, total: int}
     */
    public static function list(array $state, int $limit, int $offset): array
    {
        $db = Db::get();
        $where = [];
        $params = [];
        if ($state['search'] !== '') {
            $where[] = "(LOWER(r.path) LIKE ? ESCAPE '!' OR LOWER(r.url) LIKE ? ESCAPE '!')";
            $like = '%' . Db::escapeLike(mb_strtolower($state['search'])) . '%';
            array_push($params, $like, $like);
        }
        if ($state['kind'] === 'auto' || $state['kind'] === 'manual') {
            $where[] = 'r.auto = ?';
            $params[] = $state['kind'] === 'auto' ? 1 : 0;
        }
        $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $sort = in_array($state['sort'], self::SORTABLE, true) ? 'r.' . $state['sort'] : 'r.created';
        $dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
        $items = $db->fetchAll('SELECT r.*, pt.title AS page_title, ct.title AS category_title FROM #__redirects r
            LEFT JOIN ' . Pages::T . " pt ON r.target_type = 'page' AND pt.page_id = r.target_id AND pt.lang = r.lang
            LEFT JOIN " . Categories::T . " ct ON r.target_type = 'category' AND ct.category_id = r.target_id AND ct.lang = r.lang"
            . $filter . " ORDER BY $sort $dir, r.id DESC" . ($limit > 0 ? " LIMIT $limit OFFSET $offset" : ''), $params);
        return ['total' => (int) $db->fetchValue('SELECT COUNT(*) FROM #__redirects r' . $filter, $params), 'items' => $items];
    }

    /** @param list<int> $ids */
    public static function delete(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $count += Db::get()->delete('#__redirects', ['id' => $id]);
        }
        return $count;
    }
}
