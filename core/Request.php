<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/** Typed access to request input. Strings are valid UTF-8 (bad bytes replaced) and trimmed; arrays hold only strings. */
final class Request
{
    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /** A string from POST, falling back to GET. */
    public static function str(string $name, string $default = ''): string
    {
        $value = $_POST[$name] ?? $_GET[$name] ?? $default;
        return is_scalar($value) ? trim(mb_scrub((string) $value, 'UTF-8')) : $default;
    }

    /** Same as str() but not trimmed (passwords). */
    public static function raw(string $name): string
    {
        $value = $_POST[$name] ?? '';
        return is_scalar($value) ? mb_scrub((string) $value, 'UTF-8') : '';
    }

    public static function int(string $name, int $default = 0): int
    {
        $value = self::str($name);
        return preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }

    public static function bool(string $name): bool
    {
        return in_array(self::str($name), ['1', 'on', 'true', 'yes'], true);
    }

    /** @return list<string> */
    public static function list(string $name): array
    {
        $value = $_POST[$name] ?? $_GET[$name] ?? [];
        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }

    /** @return list<int> positive ids, e.g. checked rows of a list */
    public static function ids(string $name = 'cid'): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::list($name)), fn ($id) => $id > 0)));
    }

    /**
     * Per-language form fields posted as tr[<lang>][<field>] (values untrimmed: they may be HTML).
     * @return array<string, array<string, string>>
     */
    public static function translations(string $name = 'tr'): array
    {
        $out = [];
        foreach ((array) ($_POST[$name] ?? []) as $lang => $fields) {
            if (!is_string($lang) || !is_array($fields)) {
                continue;
            }
            foreach ($fields as $field => $value) {
                if (is_string($field) && is_string($value)) {
                    $out[$lang][$field] = mb_scrub($value, 'UTF-8');
                }
            }
        }
        return $out;
    }

    public static function has(string $name): bool
    {
        return isset($_POST[$name]) || isset($_GET[$name]);
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
