<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Earlier versions of pages, HTML blocks and the custom CSS (see Pageyard\Revisions).
    $schema->createTable('#__revisions', [
        'id'           => 'id',
        'item_type'    => 'string(20)',          // page | block | css
        'item_id'      => 'int',                 // 0 for the custom CSS
        'reason'       => 'string(10)',          // save | restore | delete: what replaced this version
        'title'        => "string(255) default ''",
        'author'       => "string(150) default ''",
        'version_date' => 'datetime',            // when this version was saved (shown)
        'created'      => 'datetime',            // when it was replaced (retention counts from here)
        'data'         => 'mediumtext',          // JSON {content, extra}
    ], [
        'index' => [['item_type', 'item_id', 'created'], ['created'], ['reason']],
    ]);
};
