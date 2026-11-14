<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Menus and their items. An item leads to the home page, a page, a category or any address (url).
 * Its label is the target's title in the language unless the item has its own label there; url items
 * need a label and appear only in languages that have one. One level of children (parent_id > 0).
 */
final class Menus
{
    public const ITEM_T = '#__menu_item_translations';
    public const TYPES = ['home', 'page', 'category', 'url', 'account'];
    /** Account pages a menu item can lead to (stored in url): '' = the profile. */
    public const ACCOUNT_PAGES = ['', 'login', 'register'];

    /** An address a menu item or a hero button may lead to. */
    public static function validUrl(string $url): bool
    {
        return preg_match('~^(https?://[^\s]+|mailto:[^\s]+|tel:[+0-9 ()-]+|/[^\s]*|#[^\s]*)$~i', $url) === 1 && strlen($url) <= 500;
    }

    public static function find(int $id): ?array
    {
        return Db::get()->fetchRow('SELECT * FROM #__menus WHERE id = ?', [$id]);
    }

    /** @return array<int, string> id => title, for selects */
    public static function options(): array
    {
        $rows = Db::get()->fetchAll('SELECT id, title FROM #__menus ORDER BY title, id');
        return array_column($rows, 'title', 'id');
    }

    /** All menus with item and block counts. */
    public static function list(): array
    {
        return Db::get()->fetchAll('SELECT m.*,
                (SELECT COUNT(*) FROM #__menu_items i WHERE i.menu_id = m.id) AS items,
                (SELECT COUNT(*) FROM #__blocks b WHERE b.menu_id = m.id) AS blocks
            FROM #__menus m ORDER BY m.title, m.id');
    }

    /** @return array{int, array<string, string>} [id, errors] */
    public static function save(int $id, string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            return [$id, ['title' => t('ERR_REQUIRED')]];
        }
        $db = Db::get();
        $row = ['title' => mb_substr($title, 0, 100), 'modified' => Db::now()];
        if ($id === 0) {
            $id = $db->insert('#__menus', $row + ['created' => $row['modified']]);
        } else {
            $db->update('#__menus', $row, ['id' => $id]);
        }
        return [$id, []];
    }

    /**
     * Deletes menus (with their items) that no block uses. Returns [count, titles kept].
     * @param list<int> $ids
     * @return array{int, list<string>}
     */
    public static function delete(array $ids): array
    {
        $db = Db::get();
        $count = 0;
        $kept = [];
        foreach ($ids as $id) {
            $menu = self::find($id);
            if ($menu === null) {
                continue;
            }
            if ($db->fetchValue('SELECT 1 FROM #__blocks WHERE menu_id = ?', [$id])) {
                $kept[] = $menu['title'];
                continue;
            }
            $count += $db->transaction(function (Db $db) use ($id) {
                foreach ($db->fetchColumn('SELECT id FROM #__menu_items WHERE menu_id = ?', [$id]) as $itemId) {
                    $db->delete(self::ITEM_T, ['item_id' => (int) $itemId]);
                }
                $db->delete('#__menu_items', ['menu_id' => $id]);
                return $db->delete('#__menus', ['id' => $id]);
            });
        }
        return [$count, $kept];
    }

    // ---- Items ----

    public static function findItem(int $id): ?array
    {
        $row = Db::get()->fetchRow('SELECT * FROM #__menu_items WHERE id = ?', [$id]);
        if ($row !== null) {
            $row['translations'] = Translations::load(self::ITEM_T, 'item_id', $id);
        }
        return $row;
    }

    /** Defaults for the "new item" form. */
    public static function blankItem(int $menuId): array
    {
        return ['id' => 0, 'menu_id' => $menuId, 'parent_id' => 0, 'type' => 'page', 'page_id' => null, 'category_id' => null,
            'url' => '', 'new_window' => 0, 'state' => 1, 'access' => 0, 'translations' => [], 'labels' => []];
    }

    /**
     * Items of a menu in display order (parents followed by their children), each with 'label' (own
     * label in $lang or null), 'target_title' (title of the target in $lang, or the address),
     * 'target_state' (null when the target is gone), 'languages' (where the item can appear) and 'level'.
     */
    public static function items(int $menuId, string $lang): array
    {
        $db = Db::get();
        $rows = $db->fetchAll('SELECT i.*, it.label FROM #__menu_items i
            LEFT JOIN ' . self::ITEM_T . ' it ON it.item_id = i.id AND it.lang = ?
            WHERE i.menu_id = ? ORDER BY i.ordering, i.id', [$lang, $menuId]);
        if (!$rows) {
            return [];
        }
        $labels = [];
        $ids = array_column($rows, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach ($db->fetchAll('SELECT item_id, lang FROM ' . self::ITEM_T . " WHERE item_id IN ($marks)", $ids) as $r) {
            $labels[(int) $r['item_id']][] = $r['lang'];
        }
        $home = Home::get();
        $targets = self::targets($rows, $home);

        $byParent = [];
        foreach ($rows as $row) {
            $type = $row['type'] === 'home' && $home !== null ? $home['type'] : $row['type'];
            $id = match ($row['type']) {
                'home'     => $home['id'] ?? 0,
                'page'     => (int) $row['page_id'],
                'category' => (int) $row['category_id'],
                default    => 0,
            };
            $target = $targets[$type][$id] ?? null;
            if ($row['type'] === 'url') {
                $row += ['target_title' => $row['url'], 'target_state' => 1, 'languages' => $labels[(int) $row['id']] ?? []];
            } elseif ($row['type'] === 'account') {
                $row += ['target_title' => self::accountTitle((string) $row['url']), 'target_state' => 1, 'languages' => Config::languages()];
            } else {
                $row += [
                    'target_title' => $target['titles'][$lang] ?? ($target !== null ? reset($target['titles']) : null),
                    'target_state' => $target['state'] ?? null,
                    'languages'    => $target !== null ? array_keys($target['titles']) : [],
                ];
            }
            sort($row['languages']);
            $byParent[(int) $row['parent_id']][] = $row;
        }
        $out = [];
        foreach ($byParent[0] ?? [] as $parent) {
            $parent['level'] = 0;
            $out[] = $parent;
            foreach ($byParent[(int) $parent['id']] ?? [] as $child) {
                $child['level'] = 1;
                $out[] = $child;
            }
        }
        // Children whose parent is gone are shown at the end (and can be fixed in the form).
        $known = array_column($out, 'id');
        foreach ($byParent as $parentId => $children) {
            foreach ($children as $row) {
                if ($parentId !== 0 && !in_array($row['id'], $known, true)) {
                    $row['level'] = 1;
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * Titles per language and state of the pages and categories the items point at.
     * @return array{page: array<int, array>, category: array<int, array>}
     */
    private static function targets(array $rows, ?array $home): array
    {
        $ids = ['page' => [], 'category' => []];
        foreach ($rows as $row) {
            if ($row['type'] === 'page' || $row['type'] === 'category') {
                $ids[$row['type']][] = (int) $row[$row['type'] . '_id'];
            }
        }
        if ($home !== null) {
            $ids[$home['type']][] = $home['id'];
        }
        $out = ['page' => [], 'category' => []];
        foreach (['page' => ['#__pages', Pages::T, 'page_id'], 'category' => ['#__categories', Categories::T, 'category_id']] as $type => [$table, $tr, $key]) {
            $list = array_values(array_unique($ids[$type]));
            if (!$list) {
                continue;
            }
            $marks = implode(',', array_fill(0, count($list), '?'));
            foreach (Db::get()->fetchAll("SELECT x.id, x.state, t.lang, t.title FROM $table x
                    LEFT JOIN $tr t ON t.$key = x.id WHERE x.id IN ($marks)", $list) as $r) {
                $out[$type][(int) $r['id']]['state'] = (int) $r['state'];
                $out[$type][(int) $r['id']]['titles'] ??= [];
                if ($r['lang'] !== null) {
                    $out[$type][(int) $r['id']]['titles'][$r['lang']] = $r['title'];
                }
            }
        }
        return $out;
    }

    /** Name of an account page ('' = profile, login, register) in the current language. */
    public static function accountTitle(string $page): string
    {
        return t(['login' => 'ACCOUNT_LOGIN', 'register' => 'ACCOUNT_REGISTER'][$page] ?? 'ACCOUNT_PROFILE');
    }

    /** Top-level items of a menu usable as parents (id => label in $lang), excluding $exceptId. */
    public static function parentOptions(int $menuId, string $lang, int $exceptId = 0): array
    {
        $out = [];
        foreach (self::items($menuId, $lang) as $item) {
            if ($item['level'] === 0 && (int) $item['id'] !== $exceptId) {
                $out[(int) $item['id']] = $item['label'] ?: ($item['target_title'] ?? '#' . $item['id']);
            }
        }
        return $out;
    }

    /**
     * @param array{menu_id: int, parent_id: int, type: string, page_id: int, category_id: int, url: string,
     *              new_window: bool, state: int, labels: array<string, string>} $data
     * @return array{int, array<string, string>}
     */
    public static function saveItem(int $id, array $data): array
    {
        $db = Db::get();
        $err = [];
        if (!self::find($data['menu_id'])) {
            $err['menu_id'] = t('ERR_REQUIRED');
        }
        $type = in_array($data['type'] ?? '', self::TYPES, true) ? $data['type'] : 'page';
        $pageId = null;
        $categoryId = null;
        $url = '';
        if ($type === 'page') {
            $pageId = $data['page_id'] > 0 && $db->fetchValue('SELECT 1 FROM #__pages WHERE id = ?', [$data['page_id']]) ? $data['page_id'] : null;
            if ($pageId === null) {
                $err['page_id'] = t('MENUS_ERR_PAGE');
            }
        } elseif ($type === 'category') {
            $categoryId = $data['category_id'] > 0 && $db->fetchValue('SELECT 1 FROM #__categories WHERE id = ?', [$data['category_id']]) ? $data['category_id'] : null;
            if ($categoryId === null) {
                $err['category_id'] = t('MENUS_ERR_CATEGORY');
            }
        } elseif ($type === 'url') {
            $url = trim($data['url']);
            if (!self::validUrl($url)) {
                $err['url'] = t('MENUS_ERR_URL');
            }
        } elseif ($type === 'account') {
            $url = in_array($data['account'] ?? '', self::ACCOUNT_PAGES, true) ? $data['account'] : '';
        }
        $labels = [];
        foreach (Config::languages() as $lang) {
            $label = trim($data['labels'][$lang] ?? '');
            if ($label !== '') {
                $labels[$lang] = ['label' => mb_substr($label, 0, 255)];
            }
        }
        $default = Config::languages()[0];
        if ($type === 'url' && !isset($labels[$default])) {
            $err["label_$default"] = t('MENUS_ERR_LABEL');
        }
        $parent = $data['parent_id'];
        if ($parent > 0) {
            $p = $db->fetchRow('SELECT menu_id, parent_id FROM #__menu_items WHERE id = ?', [$parent]);
            $hasChildren = $id > 0 && $db->fetchValue('SELECT 1 FROM #__menu_items WHERE parent_id = ?', [$id]);
            if ($p === null || (int) $p['menu_id'] !== $data['menu_id'] || (int) $p['parent_id'] !== 0 || $parent === $id || $hasChildren) {
                $err['parent_id'] = t('MENUS_ERR_PARENT');
            }
        }
        if ($err) {
            return [$id, $err];
        }

        $id = $db->transaction(function (Db $db) use ($id, $data, $parent, $type, $pageId, $categoryId, $url, $labels) {
            $row = ['menu_id' => $data['menu_id'], 'parent_id' => max(0, $parent), 'type' => $type, 'page_id' => $pageId,
                'category_id' => $categoryId, 'url' => $url, 'new_window' => $type === 'url' && $data['new_window'] ? 1 : 0,
                'state' => $data['state'] ? 1 : 0, 'access' => Access::parse((int) ($data['access'] ?? 0)), 'modified' => Db::now()];
            $old = $id ? $db->fetchRow('SELECT menu_id, parent_id FROM #__menu_items WHERE id = ?', [$id]) : null;
            if ($old === null || (int) $old['menu_id'] !== $row['menu_id'] || (int) $old['parent_id'] !== $row['parent_id']) {
                $row['ordering'] = Ordering::next('#__menu_items', ['menu_id' => $row['menu_id'], 'parent_id' => $row['parent_id']]);
            }
            if ($id === 0) {
                $id = $db->insert('#__menu_items', $row + ['created' => $row['modified']]);
            } else {
                $db->update('#__menu_items', $row, ['id' => $id]);
                // Children follow their parent into another menu.
                $db->update('#__menu_items', ['menu_id' => $row['menu_id']], ['parent_id' => $id]);
            }
            Translations::save(self::ITEM_T, 'item_id', $id, $labels);
            return $id;
        });
        return [$id, []];
    }

    /** @param list<int> $ids */
    public static function setItemState(array $ids, int $state): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $count += Db::get()->update('#__menu_items', ['state' => $state, 'modified' => Db::now()], ['id' => $id]);
        }
        return $count;
    }

    /**
     * Deletes items; their children move up to the top level.
     * @param list<int> $ids
     */
    public static function deleteItems(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $count += Db::get()->transaction(function (Db $db) use ($id) {
                $db->update('#__menu_items', ['parent_id' => 0], ['parent_id' => $id]);
                $db->delete(self::ITEM_T, ['item_id' => $id]);
                return $db->delete('#__menu_items', ['id' => $id]);
            });
        }
        return $count;
    }

    public static function moveItem(int $id, int $direction): bool
    {
        return Ordering::move('#__menu_items', $id, $direction, ['menu_id', 'parent_id']);
    }
}
