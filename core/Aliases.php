<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Pages and categories share the first URL segment (/<page alias> and /<category alias>), so an alias
 * must be unique across both tables per language, and can't be a system folder or a language code.
 */
final class Aliases
{
    /** First path segments the site can't use (folders and entry points). */
    public const RESERVED = ['administrator', 'installation', 'media', 'images', 'scripts', 'data', 'core',
        'language', 'templates', 'tests', 'index.php', Site\Search::PATH, Site\Account::PATH];

    public static function isReserved(string $alias): bool
    {
        return in_array($alias, self::RESERVED, true) || array_key_exists($alias, Lang::available())
            || in_array($alias, Config::languages(), true);
    }

    /** "Taken" check for a page alias: reserved or used by a category. */
    public static function forPage(): \Closure
    {
        return fn (string $lang, string $alias): bool => self::isReserved($alias)
            || (bool) Db::get()->fetchValue('SELECT 1 FROM ' . Categories::T . ' WHERE lang = ? AND alias = ?', [$lang, $alias]);
    }

    /** "Taken" check for a category alias: reserved or used by a page. */
    public static function forCategory(): \Closure
    {
        return fn (string $lang, string $alias): bool => self::isReserved($alias)
            || (bool) Db::get()->fetchValue('SELECT 1 FROM ' . Pages::T . ' WHERE lang = ? AND alias = ?', [$lang, $alias]);
    }
}
