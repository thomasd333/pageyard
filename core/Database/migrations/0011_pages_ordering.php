<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Manual order of the pages within a category, used by categories with the "manual" order.
    // It starts as what visitors see by default: newest first.
    $schema->addColumn('#__pages', 'ordering', 'int default 0');
    $schema->addIndex('#__pages', ['category_id', 'ordering']);
    $position = [];
    foreach ($db->fetchAll('SELECT id, category_id FROM #__pages WHERE category_id IS NOT NULL ORDER BY publish_up DESC, id DESC') as $page) {
        $position[$page['category_id']] = ($position[$page['category_id']] ?? 0) + 1;
        $db->update('#__pages', ['ordering' => $position[$page['category_id']]], ['id' => $page['id']]);
    }
};
