<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // URLs (Joomla's menu items without the menu): what is reachable on the site and how.
    $schema->createTable('#__routes', [
        'id'          => 'id',
        'type'        => 'string(20)',     // page | list | external
        'state'       => 'int default 1',  // 1 published, 0 unpublished
        'home'        => 'bool default 0', // the site's home page (one route)
        'page_id'     => 'int null',       // type page
        'category_id' => 'int null',       // type list
        'params'      => 'text null',      // JSON: list layout options, external url...
        'created'     => 'datetime',
        'modified'    => 'datetime',
    ], [
        'index' => [['page_id'], ['category_id']],
    ]);

    // Per language: the label/title and the path (null for the home page and external links).
    $schema->createTable('#__route_translations', [
        'route_id'         => 'int',
        'lang'             => 'string(10)',
        'title'            => 'string(255)',
        'path'             => 'string(190) null',
        'meta_description' => "string(300) default ''",
    ], [
        'primary' => ['route_id', 'lang'],
        'unique'  => [['lang', 'path']],
    ]);
};
