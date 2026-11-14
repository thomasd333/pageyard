<?php
/**
 * Front controller of the site.
 */

define('PY_CLIENT', 'site');
require __DIR__ . '/core/bootstrap.php';

use Pageyard\Config;
use Pageyard\Site\App;
use Pageyard\Uri;

if (!Config::isLoaded()) {
    Uri::redirect(Uri::root() . 'installation/', 302);
}

App::run();
