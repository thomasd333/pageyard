<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Flat categories; one per page. Texts live in category_translations.
    $schema->createTable('#__categories', [
        'id'       => 'id',
        'state'    => 'int default 1',     // 1 published, 0 unpublished
        'created'  => 'datetime',
        'modified' => 'datetime',
    ]);
    $schema->createTable('#__category_translations', [
        'category_id' => 'int',
        'lang'        => 'string(10)',
        'title'       => 'string(255)',
        'alias'       => 'string(190)',
        'description' => 'mediumtext null',
    ], [
        'primary' => ['category_id', 'lang'],
        'unique'  => [['lang', 'alias']],
    ]);

    // Pages (Joomla "articles"). Texts live in page_translations, one row per language.
    $schema->createTable('#__pages', [
        'id'          => 'id',
        'category_id' => 'int null',
        'state'       => 'int default 1',  // 1 published, 0 unpublished
        'image'       => "string(255) default ''",  // thumbnail, relative to the site root (images/...)
        'publish_up'  => 'datetime',       // shown as the page date; lists sort by it
        'created'     => 'datetime',
        'created_by'  => 'int null',
        'modified'    => 'datetime',
        'modified_by' => 'int null',
    ], [
        'index' => [['category_id'], ['state', 'publish_up']],
    ]);
    $schema->createTable('#__page_translations', [
        'page_id'          => 'int',
        'lang'             => 'string(10)',
        'title'            => 'string(255)',
        'alias'            => 'string(190)',
        'intro'            => 'mediumtext null',
        'body'             => 'mediumtext null',
        'meta_description' => "string(300) default ''",
    ], [
        'primary' => ['page_id', 'lang'],
        'unique'  => [['lang', 'alias']],
    ]);
};
