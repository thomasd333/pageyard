<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Blocks shown in template positions: a menu, custom HTML (per language) or a hero: a banner with an
 * image, a heading (translation title), a text (translation content) and a button (link_label per
 * language, its target in params). A hero always sits in the full-width "hero" position.
 * Assignment: everywhere, only on the selected targets, or everywhere except them. Targets are
 * "home" (the home page), "page:N" (a page) and "category:N" (a category's list and its pages).
 */
final class Blocks
{
    public const T = '#__block_translations';
    public const TYPES = ['menu', 'html', 'hero', 'search', 'user'];
    public const POSITIONS = ['top', 'hero', 'left', 'right', 'footer'];
    /** Options of a hero (the "params" column) and their defaults. */
    public const HERO = [
        'height'        => 'md',     // sm | md | lg | full
        'align'         => 'start',  // start | center | end
        'overlay'       => 50,       // 0 | 25 | 50 | 75: how much the image is darkened (%)
        'link_type'     => '',       // '' (no button) | home | page | category | url
        'link_page'     => 0,
        'link_category' => 0,
        'link_url'      => '',
        'new_window'    => false,
    ];
    public const ASSIGNMENTS = ['all', 'only', 'except'];

    public static function find(int $id): ?array
    {
        $row = Db::get()->fetchRow('SELECT * FROM #__blocks WHERE id = ?', [$id]);
        if ($row !== null) {
            $row['targets'] = self::targets($row);
            $row['params'] = self::params($row);
            $row['translations'] = Translations::load(self::T, 'block_id', $id);
        }
        return $row;
    }

    /** Hero options of a block (the column holds JSON, a form's data an array), completed with defaults. */
    public static function params(array $block): array
    {
        $stored = $block['params'] ?? [];
        $stored = is_array($stored) ? $stored : json_decode((string) $stored, true);
        return array_replace(self::HERO, array_intersect_key(is_array($stored) ? $stored : [], self::HERO));
    }

    /**
     * Checks hero options from a form; returns [options, errors keyed by field name].
     * @return array{array<string, mixed>, array<string, string>}
     */
    private static function validateHero(array $in): array
    {
        $db = Db::get();
        $err = [];
        $out = self::HERO;
        $out['height'] = in_array($in['height'] ?? '', ['sm', 'md', 'lg', 'full'], true) ? $in['height'] : 'md';
        $out['align'] = in_array($in['align'] ?? '', ['start', 'center', 'end'], true) ? $in['align'] : 'start';
        $overlay = (int) ($in['overlay'] ?? 50);
        $out['overlay'] = in_array($overlay, [0, 25, 50, 75], true) ? $overlay : 50;
        $type = in_array($in['link_type'] ?? '', ['home', 'page', 'category', 'url'], true) ? $in['link_type'] : '';
        $out['link_type'] = $type;
        if ($type === 'page') {
            $out['link_page'] = (int) ($in['link_page'] ?? 0);
            if (!$db->fetchValue('SELECT 1 FROM #__pages WHERE id = ?', [$out['link_page']])) {
                $err['link_page'] = t('MENUS_ERR_PAGE');
            }
        } elseif ($type === 'category') {
            $out['link_category'] = (int) ($in['link_category'] ?? 0);
            if (!$db->fetchValue('SELECT 1 FROM #__categories WHERE id = ?', [$out['link_category']])) {
                $err['link_category'] = t('MENUS_ERR_CATEGORY');
            }
        } elseif ($type === 'url') {
            $out['link_url'] = trim((string) ($in['link_url'] ?? ''));
            $out['new_window'] = !empty($in['new_window']);
            if (!Menus::validUrl($out['link_url'])) {
                $err['link_url'] = t('MENUS_ERR_URL');
            }
        }
        return [$out, $err];
    }

    public static function blank(string $position = 'left'): array
    {
        // A new hero starts on the home page only: a large banner on every page is rarely meant.
        $hero = $position === 'hero';
        return ['id' => 0, 'title' => '', 'type' => $hero ? 'hero' : 'html', 'position' => $position, 'menu_id' => null, 'state' => 1, 'access' => 0,
            'show_title' => 0, 'assignment' => $hero ? 'only' : 'all', 'targets' => $hero ? ['home'] : [], 'css_class' => '',
            'image' => '', 'params' => self::HERO, 'translations' => [], 'created' => null, 'modified' => null];
    }

    /** @return list<string> */
    public static function targets(array $block): array
    {
        $list = is_array($block['targets']) ? $block['targets'] : json_decode((string) $block['targets'], true);
        return is_array($list) ? array_values(array_filter($list, fn ($t) => is_string($t) && self::validTarget($t))) : [];
    }

    public static function validTarget(string $target): bool
    {
        return $target === 'home' || preg_match('/^(page|category):[1-9]\d*$/', $target) === 1;
    }

    /**
     * Blocks grouped by position in display order, with the menu title for menu blocks.
     * @param array{search: string, state: string, position: string} $state
     * @return array<string, list<array>>
     */
    public static function list(array $state): array
    {
        $where = [];
        $params = [];
        if ($state['search'] !== '') {
            $where[] = "LOWER(b.title) LIKE ? ESCAPE '!'";
            $params[] = '%' . Db::escapeLike(mb_strtolower($state['search'])) . '%';
        }
        if ($state['state'] === '1' || $state['state'] === '0') {
            $where[] = 'b.state = ?';
            $params[] = (int) $state['state'];
        }
        Access::filter($state['access'] ?? '', 'b.access', $where, $params);
        if (in_array($state['position'], self::POSITIONS, true)) {
            $where[] = 'b.position = ?';
            $params[] = $state['position'];
        }
        $rows = Db::get()->fetchAll('SELECT b.*, m.title AS menu_title FROM #__blocks b
            LEFT JOIN #__menus m ON m.id = b.menu_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY b.ordering, b.id', $params);
        $grouped = array_fill_keys(self::POSITIONS, []);
        foreach ($rows as $row) {
            $row['targets'] = self::targets($row);
            $grouped[$row['position']][] = $row;
        }
        return $grouped;
    }

    /**
     * @param array{title: string, type: string, position: string, menu_id: int, state: int, show_title: bool,
     *              assignment: string, targets: list<string>, css_class: string, image?: string, params?: array,
     *              translations: array<string, array<string, string>>} $data
     * @param string $reason what replaces the old version, for the history (save | restore)
     * @param array<string, mixed> $insert columns of a new row: id, created (a deleted block coming back)
     * @return array{int, array<string, string>}
     */
    public static function save(int $id, array $data, string $reason = 'save', array $insert = []): array
    {
        $db = Db::get();
        $err = [];
        $title = trim($data['title']);
        if ($title === '') {
            $err['title'] = t('ERR_REQUIRED');
        }
        $type = in_array($data['type'], self::TYPES, true) ? $data['type'] : 'html';
        $position = in_array($data['position'], self::POSITIONS, true) ? $data['position'] : 'left';
        $assignment = in_array($data['assignment'], self::ASSIGNMENTS, true) ? $data['assignment'] : 'all';
        $menuId = null;
        if ($type === 'menu') {
            $menuId = Menus::find($data['menu_id']) ? $data['menu_id'] : null;
            if ($menuId === null) {
                $err['menu_id'] = t('BLOCKS_ERR_MENU');
            }
        }
        $targets = array_values(array_unique(array_filter(array_map('strval', $data['targets']), self::validTarget(...))));
        if ($assignment !== 'all' && !$targets) {
            $err['targets'] = t('BLOCKS_ERR_TARGETS');
        }
        $cssClass = trim($data['css_class']);
        if ($cssClass !== '' && !preg_match('/^[A-Za-z0-9_\- ]{1,100}$/', $cssClass)) {
            $err['css_class'] = t('BLOCKS_ERR_CLASS');
        }
        $image = '';
        $params = null;
        if ($type === 'hero') {
            $position = 'hero';
            $image = trim((string) ($data['image'] ?? ''));
            if ($image !== '' && !Media::isImagePath($image)) {
                $err['image'] = t('PAGES_ERR_IMAGE');
            }
            [$params, $heroErrors] = self::validateHero($data['params'] ?? []);
            $err += $heroErrors;
        }

        $rows = [];
        foreach (Config::languages() as $lang) {
            $tr = $data['translations'][$lang] ?? [];
            $trTitle = mb_substr(trim($tr['title'] ?? ''), 0, 255);
            $content = match ($type) {
                'html'  => trim($tr['content'] ?? ''),
                'hero'  => trim($tr['text'] ?? $tr['content'] ?? ''),  // the form's plain field; a restored version has content
                default => '',
            };
            $label = $type === 'hero' ? mb_substr(trim($tr['link_label'] ?? ''), 0, 255) : '';
            if ($trTitle !== '' || $content !== '' || $label !== '') {
                $rows[$lang] = ['title' => $trTitle, 'content' => $content !== '' ? $content : null, 'link_label' => $label];
            }
        }
        if ($type === 'hero' && $image === '' && !isset($err['image']) && !$rows) {
            $err['image'] = t('BLOCKS_ERR_HERO_EMPTY');
        }
        if ($err) {
            return [$id, $err];
        }

        $id = $db->transaction(function (Db $db) use ($id, $title, $type, $position, $menuId, $data, $assignment, $targets, $cssClass, $image, $params, $rows, $reason, $insert) {
            $before = $id ? self::revision($id) : null;
            $row = [
                'title' => mb_substr($title, 0, 255), 'type' => $type, 'position' => $position, 'menu_id' => $menuId,
                'state' => $data['state'] ? 1 : 0, 'show_title' => $data['show_title'] ? 1 : 0, 'assignment' => $assignment,
                'targets' => $assignment === 'all' ? null : json_encode($targets), 'css_class' => $cssClass, 'modified' => Db::now(),
                'image' => $image, 'params' => $params === null ? null : json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
            if (isset($data['access'])) {  // left out (a restore): the stored access stays
                $row['access'] = Access::parse((int) $data['access']);
            }
            $old = $id ? $db->fetchValue('SELECT position FROM #__blocks WHERE id = ?', [$id]) : null;
            if ($old !== $position) {
                $row['ordering'] = Ordering::next('#__blocks', ['position' => $position]);
            }
            if ($id === 0) {
                $newId = $db->insert('#__blocks', $row + $insert + ['created' => $row['modified']]);
                $id = (int) ($insert['id'] ?? $newId);
            } else {
                $db->update('#__blocks', $row, ['id' => $id]);
            }
            Translations::save(self::T, 'block_id', $id, $rows);
            Revisions::saved('block', $id, $before, self::revision($id), $reason);
            return $id;
        });
        return [$id, []];
    }

    /** @param list<int> $ids */
    public static function setState(array $ids, int $state): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $count += Db::get()->update('#__blocks', ['state' => $state, 'modified' => Db::now()], ['id' => $id]);
        }
        return $count;
    }

    /** @param list<int> $ids */
    public static function delete(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            $count += Db::get()->transaction(function (Db $db) use ($id) {
                $before = self::revision($id);
                if ($before !== null) {
                    Revisions::record('block', $id, $before, 'delete');
                }
                $db->delete(self::T, ['block_id' => $id]);
                return $db->delete('#__blocks', ['id' => $id]);
            });
        }
        return $count;
    }

    /**
     * An HTML or hero block's current version for the history; null for menu and search blocks, which have no
     * text of their own. "extra" is only used when a deleted block returns.
     */
    public static function revision(int $id): ?array
    {
        $block = self::find($id);
        if ($block === null || !in_array($block['type'], ['html', 'hero'], true)) {
            return null;
        }
        $hero = $block['type'] === 'hero';
        $translations = [];
        foreach ($block['translations'] as $lang => $tr) {
            $translations[$lang] = ['title' => $tr['title'], 'content' => $tr['content']] + ($hero ? ['link_label' => $tr['link_label']] : []);
        }
        ksort($translations);
        return [
            'title'   => $block['title'],
            'date'    => $block['modified'],
            'author'  => '',
            'content' => ['title' => $block['title'], 'translations' => $translations]
                + ($hero ? ['type' => 'hero', 'image' => $block['image'], 'params' => $block['params']] : []),
            'extra'   => ['position' => $block['position'], 'show_title' => (int) $block['show_title'], 'assignment' => $block['assignment'],
                'targets' => $block['targets'], 'css_class' => $block['css_class'], 'created' => $block['created']],
        ];
    }

    /**
     * Brings back a version from the history: the name and the texts. Position, visibility and status
     * stay as they are. A deleted block returns under its old id, unpublished.
     * @return array{int, array<string, string>, list<string>}
     */
    public static function restore(int $id, array $content, array $extra): array
    {
        $current = self::find($id);
        $source = $current ?? $extra;
        $data = [
            'title'        => (string) ($content['title'] ?? ''),
            'type'         => (string) ($content['type'] ?? 'html'),
            'image'        => (string) ($content['image'] ?? ''),
            'params'       => (array) ($content['params'] ?? []),
            'position'     => (string) ($source['position'] ?? 'left'),
            'menu_id'      => 0,
            'state'        => $current !== null ? (int) $current['state'] : 0,
            'show_title'   => !empty($source['show_title']),
            'assignment'   => (string) ($source['assignment'] ?? 'all'),
            'targets'      => (array) ($source['targets'] ?? []),
            'css_class'    => (string) ($source['css_class'] ?? ''),
            'translations' => (array) ($content['translations'] ?? []),
        ];
        [$newId, $errors] = $current !== null
            ? self::save($id, $data, 'restore')
            : self::save(0, $data, 'restore', ['id' => $id, 'created' => $extra['created'] ?? Db::now()]);
        return [$newId, $errors, []];
    }

    public static function move(int $id, int $direction): bool
    {
        return Ordering::move('#__blocks', $id, $direction, ['position']);
    }

    /**
     * Whether a block is shown on the current view.
     * @param array{home: bool, page: ?int, category: ?int}|null $context null = 404 and similar
     */
    public static function showsOn(array $block, ?array $context): bool
    {
        if ($block['assignment'] === 'all') {
            return true;
        }
        $hit = false;
        if ($context !== null) {
            foreach (self::targets($block) as $target) {
                if (($target === 'home' && $context['home'])
                    || ($context['page'] !== null && $target === 'page:' . $context['page'])
                    || ($context['category'] !== null && $target === 'category:' . $context['category'])) {
                    $hit = true;
                    break;
                }
            }
        }
        return $block['assignment'] === 'only' ? $hit : !$hit;
    }
}
