<?php
/**
 * Global helpers used in templates and views.
 */

defined('PAGEYARD') or exit;

/** Translates a language key; extra arguments go through sprintf. */
function t(string $key, mixed ...$args): string
{
    return Pageyard\Lang::t($key, ...$args);
}

/** Escapes a value for HTML text and attributes. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formats a UTC datetime column in the site time zone; empty for null. */
function py_date(?string $utc, string $format = 'Y-m-d H:i'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
}
