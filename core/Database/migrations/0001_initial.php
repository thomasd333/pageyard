<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Back-end users. Usernames and e-mails are stored lowercase, so uniqueness
    // behaves the same in SQLite (case-sensitive) and MySQL (case-insensitive).
    $schema->createTable('#__users', [
        'id'         => 'id',
        'name'       => 'string(255)',
        'username'   => 'string(150)',
        'email'      => 'string(255)',
        'password'   => 'string(255)',
        'active'     => 'bool default 1',
        'last_login' => 'datetime null',
        'created'    => 'datetime',
        'modified'   => 'datetime',
    ], [
        'unique' => [['username'], ['email']],
    ]);

    // Site-wide settings edited in the back end (template layout, custom CSS...).
    $schema->createTable('#__settings', [
        'name'  => 'string(100) primary',
        'value' => 'mediumtext null',
    ]);
};
