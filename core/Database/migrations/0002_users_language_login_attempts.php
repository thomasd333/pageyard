<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Administrator language per user; empty = admin_language from configuration.php.
    $schema->addColumn('#__users', 'language', "string(10) default ''");

    // Failed logins, used to slow down password guessing.
    $schema->createTable('#__login_attempts', [
        'id'       => 'id',
        'ip'       => 'string(45)',
        'username' => 'string(150)',
        'created'  => 'datetime',
    ], [
        'index' => [['ip'], ['username'], ['created']],
    ]);
};
