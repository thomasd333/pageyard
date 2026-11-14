<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

/**
 * URLs come from the content itself: /<page alias>, /<category alias>, /<category alias>/<page alias>.
 * The routes tables go away: list options move to categories, the home page becomes a setting,
 * menu items point at content directly and blocks are assigned to the home page, categories or pages.
 * Existing routes are converted. Self-contained on purpose (no model classes, they may change later).
 */
return function (Schema $schema, Db $db): void {
    $listDefaults = ['layout' => 'blog', 'columns' => 1, 'intro_count' => 6, 'link_count' => 4, 'per_page' => 20,
        'order' => 'date_desc', 'show_date' => true, 'show_image' => true, 'show_description' => true];
    $reserved = ['administrator', 'installation', 'media', 'images', 'scripts', 'data', 'core', 'language',
        'templates', 'tests', 'index.php'];
    foreach (glob(PY_ROOT . '/language/*/meta.php') ?: [] as $meta) {
        $reserved[] = basename(dirname($meta));
    }

    $schema->addColumn('#__categories', 'params', 'text null');
    $schema->addColumn('#__category_translations', 'meta_description', "string(300) default ''");

    // ---- Read the old data ----
    $routes = [];
    foreach ($db->fetchAll('SELECT * FROM #__routes ORDER BY home DESC, id') as $r) {
        $r['params'] = json_decode((string) $r['params'], true) ?: [];
        $r['tr'] = [];
        $routes[(int) $r['id']] = $r;
    }
    foreach ($db->fetchAll('SELECT * FROM #__route_translations') as $t) {
        if (isset($routes[(int) $t['route_id']])) {
            $routes[(int) $t['route_id']]['tr'][$t['lang']] = $t;
        }
    }
    $items = $db->fetchAll('SELECT * FROM #__menu_items ORDER BY id');
    $labels = [];
    foreach ($db->fetchAll('SELECT * FROM #__menu_item_translations') as $t) {
        $labels[(int) $t['item_id']][$t['lang']] = $t['label'];
    }
    $blocks = $db->fetchAll('SELECT * FROM #__blocks ORDER BY id');
    $pageTitles = [];
    foreach ($db->fetchAll('SELECT page_id, lang, title FROM #__page_translations') as $t) {
        $pageTitles[(int) $t['page_id']][$t['lang']] = $t['title'];
    }
    $categoryTitles = [];
    foreach ($db->fetchAll('SELECT category_id, lang, title FROM #__category_translations') as $t) {
        $categoryTitles[(int) $t['category_id']][$t['lang']] = $t['title'];
    }

    // ---- Categories: list options and meta descriptions from their (first) list route ----
    $done = [];
    foreach ($routes as $r) {
        if ($r['type'] !== 'list' || $r['category_id'] === null || isset($done[(int) $r['category_id']])) {
            continue;
        }
        $id = (int) $r['category_id'];
        $done[$id] = true;
        $params = array_intersect_key($r['params'], $listDefaults) + $listDefaults;
        $db->update('#__categories', ['params' => json_encode($params)], ['id' => $id]);
        foreach ($r['tr'] as $lang => $t) {
            if ($t['meta_description'] !== '') {
                $db->update('#__category_translations', ['meta_description' => $t['meta_description']], ['category_id' => $id, 'lang' => $lang]);
            }
        }
    }

    // ---- Home page ----
    foreach ($routes as $r) {
        if ($r['home'] && in_array($r['type'], ['page', 'list'], true)) {
            $home = $r['type'] === 'page' ? ['type' => 'page', 'id' => (int) $r['page_id']] : ['type' => 'category', 'id' => (int) $r['category_id']];
            $db->delete('#__settings', ['name' => 'home']);
            $db->insert('#__settings', ['name' => 'home', 'value' => json_encode($home)]);
            break;
        }
    }

    // ---- Aliases: pages and categories share /<alias>, and system folders / language codes are taken ----
    $taken = [];  // lang => alias => true
    foreach ($db->fetchAll('SELECT lang, alias FROM #__page_translations') as $t) {
        $taken[$t['lang']][$t['alias']] = true;
    }
    $unique = function (string $lang, string $alias) use (&$taken, $reserved): string {
        $candidate = $alias;
        for ($n = 2; isset($taken[$lang][$candidate]) || in_array($candidate, $reserved, true); $n++) {
            $candidate = substr($alias, 0, 180) . "-$n";
        }
        $taken[$lang][$candidate] = true;
        return $candidate;
    };
    foreach ($db->fetchAll('SELECT page_id, lang, alias FROM #__page_translations') as $t) {
        if (in_array($t['alias'], $reserved, true)) {
            $db->update('#__page_translations', ['alias' => $unique($t['lang'], $t['alias'])], ['page_id' => $t['page_id'], 'lang' => $t['lang']]);
        }
    }
    foreach ($db->fetchAll('SELECT category_id, lang, alias FROM #__category_translations ORDER BY category_id') as $t) {
        $alias = $unique($t['lang'], $t['alias']);
        if ($alias !== $t['alias']) {
            $db->update('#__category_translations', ['alias' => $alias], ['category_id' => $t['category_id'], 'lang' => $t['lang']]);
        }
    }

    // ---- Menu items: rebuilt with a target type instead of route_id ----
    $schema->dropTable('#__menu_items');
    $schema->createTable('#__menu_items', [
        'id'          => 'id',
        'menu_id'     => 'int',
        'parent_id'   => 'int default 0',
        'type'        => "string(20) default 'page'",  // home | page | category | url
        'page_id'     => 'int null',
        'category_id' => 'int null',
        'url'         => "string(500) default ''",
        'new_window'  => 'bool default 0',
        'state'       => 'int default 1',
        'ordering'    => 'int default 0',
        'created'     => 'datetime',
        'modified'    => 'datetime',
    ], [
        'index' => [['menu_id', 'parent_id', 'ordering'], ['page_id'], ['category_id']],
    ]);
    foreach ($items as $item) {
        $route = $routes[(int) $item['route_id']] ?? null;
        $id = (int) $item['id'];
        if ($route === null) {
            $db->delete('#__menu_item_translations', ['item_id' => $id]);
            continue;  // pointed at a deleted URL
        }
        $row = ['id' => $id, 'menu_id' => $item['menu_id'], 'parent_id' => $item['parent_id'], 'state' => $item['state'],
            'ordering' => $item['ordering'], 'created' => $item['created'], 'modified' => $item['modified']];
        $targetTitles = [];
        if ($route['home']) {
            $row['type'] = 'home';
            $targetTitles = $route['type'] === 'page' ? ($pageTitles[(int) $route['page_id']] ?? []) : ($categoryTitles[(int) $route['category_id']] ?? []);
        } elseif ($route['type'] === 'page') {
            $row += ['type' => 'page', 'page_id' => $route['page_id']];
            $targetTitles = $pageTitles[(int) $route['page_id']] ?? [];
        } elseif ($route['type'] === 'list') {
            $row += ['type' => 'category', 'category_id' => $route['category_id']];
            $targetTitles = $categoryTitles[(int) $route['category_id']] ?? [];
        } else {
            $row += ['type' => 'url', 'url' => (string) ($route['params']['url'] ?? ''), 'new_window' => empty($route['params']['new_window']) ? 0 : 1];
        }
        $db->insert('#__menu_items', $row);
        // Keep what visitors saw: the URL's title becomes the label where the item had none and it differs.
        foreach ($route['tr'] as $lang => $t) {
            if (!isset($labels[$id][$lang]) && $t['title'] !== ($targetTitles[$lang] ?? null)) {
                $db->insert('#__menu_item_translations', ['item_id' => $id, 'lang' => $lang, 'label' => $t['title']]);
            }
        }
    }

    // ---- Blocks: "targets" (home, page:N, category:N) instead of route ids ----
    $schema->dropTable('#__blocks');
    $schema->createTable('#__blocks', [
        'id'         => 'id',
        'title'      => 'string(255)',
        'type'       => 'string(20)',
        'position'   => 'string(20)',
        'menu_id'    => 'int null',
        'state'      => 'int default 1',
        'ordering'   => 'int default 0',
        'show_title' => 'bool default 0',
        'assignment' => "string(10) default 'all'",  // all | only | except
        'targets'    => 'text null',                  // JSON list: "home", "page:N", "category:N"
        'css_class'  => "string(100) default ''",
        'created'    => 'datetime',
        'modified'   => 'datetime',
    ], [
        'index' => [['position', 'ordering']],
    ]);
    foreach ($blocks as $block) {
        $targets = [];
        foreach (json_decode((string) $block['routes'], true) ?: [] as $routeId) {
            $route = $routes[(int) $routeId] ?? null;
            if ($route === null || $route['type'] === 'external') {
                continue;
            }
            $targets[] = match (true) {
                (bool) $route['home']      => 'home',
                $route['type'] === 'page'  => 'page:' . (int) $route['page_id'],
                default                    => 'category:' . (int) $route['category_id'],
            };
        }
        $targets = array_values(array_unique($targets));
        $assignment = $block['assignment'];
        if ($assignment !== 'all' && !$targets) {
            $assignment = $assignment === 'only' ? 'only' : 'all';
        }
        unset($block['routes']);
        $db->insert('#__blocks', ['assignment' => $assignment, 'targets' => $assignment === 'all' ? null : json_encode($targets)] + $block);
    }

    $schema->dropTable('#__route_translations');
    $schema->dropTable('#__routes');
};
