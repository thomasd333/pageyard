<?php

use Pageyard\Database\Db;
use Pageyard\Database\Schema;

defined('PAGEYARD') or exit;

return function (Schema $schema, Db $db): void {
    // Hero blocks: an image and options on the block, a button label per language. The heading and
    // the text use the existing title and content columns of the translations.
    $schema->addColumn('#__blocks', 'image', "string(255) default ''");
    $schema->addColumn('#__blocks', 'params', 'text null');     // JSON, see Blocks::HERO
    $schema->addColumn('#__block_translations', 'link_label', "string(255) default ''");
};
