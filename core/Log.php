<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/** Appends lines to data/logs/pageyard.log. Logging never throws. */
final class Log
{
    /** Log file; tests point it elsewhere. */
    public static string $file = PY_ROOT . '/data/logs/pageyard.log';

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    private static function write(string $level, string $message): void
    {
        $dir = dirname(self::$file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return;
        }
        $line = sprintf("[%s] %s %s: %s\n", date('Y-m-d H:i:s'), $level, PY_CLIENT, $message);
        @file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX);
    }
}
