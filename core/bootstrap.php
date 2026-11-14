<?php
/**
 * Common start-up for every entry point (site, administrator, installation, tests).
 * The entry point defines PY_CLIENT before including this file.
 */

defined('PAGEYARD') or define('PAGEYARD', 1);
defined('PY_ROOT') or define('PY_ROOT', dirname(__DIR__));
defined('PY_CLIENT') or define('PY_CLIENT', 'site');

const PY_VERSION = '1.1.0';
const PY_MIN_PHP = '8.3.0';

if (version_compare(PHP_VERSION, PY_MIN_PHP, '<')) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    exit('Pageyard needs PHP ' . PY_MIN_PHP . ' or newer (this server runs ' . PHP_VERSION . ').');
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pageyard\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require __DIR__ . '/functions.php';

mb_internal_encoding('UTF-8');
Pageyard\ErrorHandler::register();

if (Pageyard\Config::exists()) {
    Pageyard\Config::load();
    date_default_timezone_set(Pageyard\Config::get('timezone', 'UTC'));
    // New versions bring new migrations; apply them on the first request, after a backup of the
    // database as it was (SQLite; a failed backup is logged and doesn't stop the update).
    if (PY_CLIENT === 'site' || PY_CLIENT === 'administrator') {
        $migrator = new Pageyard\Database\Migrator(Pageyard\Database\Db::get());
        if ($migrator->pending()) {
            if (Pageyard\Database\Db::get()->tableExists('#__migrations') && Pageyard\Backup::supported()) {
                try {
                    Pageyard\Backup::create('update');
                } catch (\Throwable) {
                }
            }
            $migrator->migrate();
        }
    }
} else {
    date_default_timezone_set('UTC');
}
