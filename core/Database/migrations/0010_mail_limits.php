<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // One open password reset per user; only a hash of the token is kept.
    $schema->createTable('#__password_resets', [
        'user_id'    => 'int primary',
        'token_hash' => 'string(64)',
        'created'    => 'datetime',
    ], [
        'unique' => [['token_hash']],
    ]);
    // Limits on public actions (contact form, password reset). Keys are hashed, so no addresses are stored.
    $schema->createTable('#__rate_limits', [
        'id'       => 'id',
        'action'   => 'string(20)',
        'key_hash' => 'string(64)',
        'created'  => 'datetime',
    ], [
        'index' => [['action', 'key_hash'], ['created']],
    ]);
};
