<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Menus: named sets of items; a menu is shown on the site through a block.
    $schema->createTable('#__menus', [
        'id'       => 'id',
        'title'    => 'string(100)',
        'created'  => 'datetime',
        'modified' => 'datetime',
    ]);

    // Menu items point at URLs. parent_id 0 = top level; one level of children (dropdowns).
    $schema->createTable('#__menu_items', [
        'id'        => 'id',
        'menu_id'   => 'int',
        'parent_id' => 'int default 0',
        'route_id'  => 'int',
        'state'     => 'int default 1',
        'ordering'  => 'int default 0',
        'created'   => 'datetime',
        'modified'  => 'datetime',
    ], [
        'index' => [['menu_id', 'parent_id', 'ordering'], ['route_id']],
    ]);
    // Optional label per language; without it the item shows the URL's title.
    $schema->createTable('#__menu_item_translations', [
        'item_id' => 'int',
        'lang'    => 'string(10)',
        'label'   => 'string(255)',
    ], [
        'primary' => ['item_id', 'lang'],
    ]);

    // Blocks in template positions (top, left, right, footer): a menu or custom HTML.
    $schema->createTable('#__blocks', [
        'id'         => 'id',
        'title'      => 'string(255)',        // name in the administrator
        'type'       => 'string(20)',         // menu | html
        'position'   => 'string(20)',         // top | left | right | footer
        'menu_id'    => 'int null',
        'state'      => 'int default 1',
        'ordering'   => 'int default 0',
        'show_title' => 'bool default 0',
        'assignment' => "string(10) default 'all'",  // all | only | except
        'routes'     => 'text null',          // JSON list of route ids for only / except
        'css_class'  => "string(100) default ''",
        'created'    => 'datetime',
        'modified'   => 'datetime',
    ], [
        'index' => [['position', 'ordering']],
    ]);
    // Per language: the title shown on the site and the HTML content.
    $schema->createTable('#__block_translations', [
        'block_id' => 'int',
        'lang'     => 'string(10)',
        'title'    => "string(255) default ''",
        'content'  => 'mediumtext null',
    ], [
        'primary' => ['block_id', 'lang'],
    ]);
};
