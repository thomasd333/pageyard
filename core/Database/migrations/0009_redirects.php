<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Old addresses that lead somewhere else (see Pageyard\Redirects).
    $schema->createTable('#__redirects', [
        'id'          => 'id',
        'lang'        => 'string(10)',
        'path'        => 'string(255)',             // below the language prefix, no surrounding slashes
        'target_type' => 'string(10)',              // page | category | url
        'target_id'   => 'int default 0',
        'url'         => "string(500) default ''",  // url: https://... or a path from the site root
        'auto'        => 'bool default 0',          // made by renaming or moving an item
        'hits'        => 'int default 0',
        'last_hit'    => 'datetime null',
        'created'     => 'datetime',
    ], [
        'unique' => [['lang', 'path']],
    ]);
};
