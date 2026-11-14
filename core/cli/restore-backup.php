<?php
/**
 * Restores a database backup when the administrator won't open:
 *
 *     php core/cli/restore-backup.php               lists the backups
 *     php core/cli/restore-backup.php <file name>   restores one
 *
 * The current state is saved first if it can be read; a damaged database file is kept beside the new one.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('PY_CLIENT', 'cli');
require dirname(__DIR__) . '/bootstrap.php';

use Pageyard\Backup;
use Pageyard\Config;
use Pageyard\Lang;

if (!Config::exists()) {
    fwrite(STDERR, "Pageyard is not installed here (no configuration.php).\n");
    exit(1);
}
if (!Backup::supported()) {
    fwrite(STDERR, "Backups are available for SQLite databases only.\n");
    exit(1);
}
Lang::set('en');

$name = $argv[1] ?? '';
if ($name === '') {
    echo "Usage: php core/cli/restore-backup.php <file name>\n\nBackups, newest first:\n";
    foreach (Backup::list() as $b) {
        echo '  ' . $b['name'] . '  (' . max(1, (int) round($b['size'] / 1024)) . " KB)\n";
    }
    exit(0);
}

try {
    $result = Backup::restore($name);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Not restored, the database is as it was: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Restored $name.\n";
if ($result['safety'] !== null) {
    echo "The previous state is in the backup {$result['safety']}.\n";
}
if ($result['damaged'] !== null) {
    echo "The previous database file was kept as data/{$result['damaged']}.\n";
}
