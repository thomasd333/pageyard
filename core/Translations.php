<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Per-language texts kept in <item>_translations tables (PK item id + lang, unique lang + alias).
 * A language without a title has no row; the default language is required.
 */
final class Translations
{
    /**
     * @param string $table e.g. '#__page_translations'
     * @param string $key   e.g. 'page_id'
     * @return array<string, array<string, mixed>> lang => row
     */
    public static function load(string $table, string $key, int $id): array
    {
        $rows = [];
        foreach (Db::get()->fetchAll("SELECT * FROM $table WHERE $key = ?", [$id]) as $row) {
            $rows[$row['lang']] = $row;
        }
        return $rows;
    }

    /**
     * Normalises posted translations and validates titles and aliases.
     * Empty aliases are generated from the title; taken ones get -2, -3... appended.
     *
     * @param array<string, array<string, string>> $posted lang => fields (title, alias, ...)
     * @param list<string> $languages enabled languages, default first
     * @param ?\Closure(string $lang, string $alias): bool $taken extra check, e.g. aliases used by another table
     * @return array{array<string, array<string, string>>, array<string, string>, list<string>}
     *         [rows to keep (lang => fields), errors keyed "lang.field", languages whose alias was changed]
     */
    public static function prepare(string $table, string $key, int $id, array $posted, array $languages, ?\Closure $taken = null): array
    {
        $rows = [];
        $errors = [];
        $renamed = [];
        foreach ($languages as $i => $lang) {
            $row = array_map(fn ($v) => is_string($v) ? $v : '', $posted[$lang] ?? []);
            $row['title'] = trim($row['title'] ?? '');
            if ($row['title'] === '') {
                if ($i === 0) {
                    $errors["$lang.title"] = t('ERR_REQUIRED');
                }
                continue;  // optional language left empty
            }
            if (mb_strlen($row['title']) > 255) {
                $errors["$lang.title"] = t('ERR_TOO_LONG', 255);
            }
            $typed = trim($row['alias'] ?? '');
            $alias = Str::slug($typed !== '' ? $typed : $row['title']);
            if ($alias === '') {
                $alias = Str::slug($row['title']) ?: gmdate('Y-m-d-His');
            }
            $unique = self::uniqueAlias($table, $key, $id, $lang, $alias, $taken);
            if ($unique !== $alias) {
                $renamed[] = $lang;
            }
            $row['alias'] = $unique;
            $rows[$lang] = $row;
        }
        return [$rows, $errors, $renamed];
    }

    /**
     * Replaces the item's translations with $rows (lang => column values).
     * Languages missing from $rows are deleted.
     */
    public static function save(string $table, string $key, int $id, array $rows): void
    {
        $db = Db::get();
        $db->delete($table, [$key => $id]);
        foreach ($rows as $lang => $row) {
            $db->insert($table, [$key => $id, 'lang' => $lang] + $row);
        }
    }

    /** $alias, or $alias-2, -3... if another item uses it in that language (or $taken says so). */
    public static function uniqueAlias(string $table, string $key, int $id, string $lang, string $alias, ?\Closure $taken = null): string
    {
        $db = Db::get();
        $candidate = $alias;
        for ($n = 2; $db->fetchValue("SELECT 1 FROM $table WHERE lang = ? AND alias = ? AND $key <> ?", [$lang, $candidate, $id])
            || ($taken !== null && $taken($lang, $candidate)); $n++) {
            $suffix = "-$n";
            $candidate = substr($alias, 0, 190 - strlen($suffix)) . $suffix;
        }
        return $candidate;
    }
}
