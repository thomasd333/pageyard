<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Who sees it on the site: 0 everyone, 1 logged-in users only, 2 guests only (menu items and blocks).
    foreach (['#__pages', '#__categories', '#__menu_items', '#__blocks'] as $table) {
        $schema->addColumn($table, 'access', 'int default 0');
    }
};
