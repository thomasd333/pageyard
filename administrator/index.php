<?php
/**
 * Front controller of the administrator.
 */

define('PY_CLIENT', 'administrator');
require dirname(__DIR__) . '/core/bootstrap.php';

use Pageyard\Admin\Dispatcher;
use Pageyard\Config;
use Pageyard\Uri;

if (!Config::isLoaded()) {
    Uri::redirect(Uri::root() . 'installation/', 302);
}

Dispatcher::run();
