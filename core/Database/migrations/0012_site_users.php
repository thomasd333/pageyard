<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Administrators may use the back end; other users only log in on the site. Everyone who
    // existed so far was an administrator.
    $schema->addColumn('#__users', 'admin', 'bool default 0');
    $db->query('UPDATE #__users SET admin = 1');
    // What a new account still waits for: '' nothing, 'email' its owner's confirmation, 'admin' approval.
    $schema->addColumn('#__users', 'pending', "string(10) default ''");

    // One-time links other than password resets: confirming a new account or a new e-mail address.
    // Only a hash of the token is kept; data holds the new address.
    $schema->createTable('#__user_tokens', [
        'user_id'    => 'int',
        'purpose'    => 'string(20)',
        'token_hash' => 'string(64)',
        'data'       => 'string(255) null',
        'created'    => 'datetime',
    ], [
        'primary' => ['user_id', 'purpose'],
        'unique'  => [['token_hash']],
    ]);
};
