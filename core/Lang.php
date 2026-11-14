<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/**
 * Interface strings. language/<tag>/meta.php describes a language, every other
 * file in that folder returns an array of key => text. English is the fallback.
 */
final class Lang
{
    public const FALLBACK = 'en';

    private static string $tag = self::FALLBACK;
    /** @var array<string, array<string, string>> */
    private static array $strings = [];

    public static function dir(): string
    {
        return PY_ROOT . '/language';
    }

    /** @return array<string, array{name: string, locale: string}> tag => meta */
    public static function available(): array
    {
        $list = [];
        foreach (glob(self::dir() . '/*/meta.php') ?: [] as $meta) {
            $list[basename(dirname($meta))] = require $meta;
        }
        ksort($list);
        return $list;
    }

    /**
     * Display names for the given tags, in the given order (e.g. Config::languages(), default first).
     * @return array<string, string>
     */
    public static function names(array $tags): array
    {
        $available = self::available();
        $names = [];
        foreach ($tags as $tag) {
            $names[$tag] = $available[$tag]['name'] ?? strtoupper($tag);
        }
        return $names;
    }

    public static function exists(string $tag): bool
    {
        return preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $tag) === 1 && is_file(self::dir() . "/$tag/meta.php");
    }

    public static function set(string $tag): void
    {
        self::$tag = self::exists($tag) ? $tag : self::FALLBACK;
        self::loadStrings(self::FALLBACK);
        self::loadStrings(self::$tag);
    }

    public static function current(): string
    {
        return self::$tag;
    }

    /** Locale of the current language, e.g. "pl_PL". */
    public static function locale(): string
    {
        return (string) (self::available()[self::$tag]['locale'] ?? 'en_GB');
    }

    /** A UTC datetime column as a long local date ("3 listopada 2026"); falls back to Y-m-d without intl. */
    public static function date(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(self::locale(), \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, date_default_timezone_get());
            $text = $formatter->format(new \DateTimeImmutable($utc, new \DateTimeZone('UTC')));
            if ($text !== false) {
                return $text;
            }
        }
        return py_date($utc, 'Y-m-d');
    }

    public static function t(string $key, mixed ...$args): string
    {
        $text = self::$strings[self::$tag][$key] ?? self::$strings[self::FALLBACK][$key] ?? $key;
        return $args ? vsprintf($text, $args) : $text;
    }

    /** Picks a supported language from the browser's Accept-Language header. */
    public static function fromBrowser(string $default = self::FALLBACK): string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        foreach (explode(',', $header) as $part) {
            $tag = strtolower(substr(trim($part), 0, 2));
            if ($tag !== '' && self::exists($tag)) {
                return $tag;
            }
        }
        return $default;
    }

    private static function loadStrings(string $tag): void
    {
        if (isset(self::$strings[$tag])) {
            return;
        }
        $strings = [];
        foreach (glob(self::dir() . "/$tag/*.php") ?: [] as $file) {
            if (basename($file) !== 'meta.php') {
                $strings += require $file;
            }
        }
        self::$strings[$tag] = $strings;
    }
}
