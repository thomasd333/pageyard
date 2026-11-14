<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/** Site settings kept in #__settings (JSON values), e.g. the template layout and custom CSS. */
final class Settings
{
    /** Template options and their defaults. */
    public const TEMPLATE = [
        'layout'         => 'panels',     // panels | sidebar (left blocks as a full-height column, offcanvas on phones)
        'container'      => 'container',  // container | container-fluid (the sidebar layout is always fluid)
        'show_left'      => true,         // panels are shown only when on and they have blocks
        'show_right'     => true,
        'left_width'     => 3,            // Bootstrap columns (2-4) on large screens
        'right_width'    => 3,
        'navbar'         => 'dark',       // dark | light | primary
        'navbar_sticky'  => false,
        'color_mode'     => 'light',      // light | dark | auto (follows the visitor's system)
        'logo'           => '',           // images/... or ''
        'show_sitename'  => true,
        'show_copyright' => true,
    ];

    /** @var array<string, mixed> */
    private static array $cache = [];

    public static function get(string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, self::$cache)) {
            $value = Db::get()->fetchValue('SELECT value FROM #__settings WHERE name = ?', [$name]);
            self::$cache[$name] = $value === null ? null : json_decode((string) $value, true);
        }
        return self::$cache[$name] ?? $default;
    }

    public static function set(string $name, mixed $value): void
    {
        $db = Db::get();
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $db->transaction(function (Db $db) use ($name, $json) {
            $db->delete('#__settings', ['name' => $name]);
            $db->insert('#__settings', ['name' => $name, 'value' => $json]);
        });
        self::$cache[$name] = $value;
    }

    /** Forgets cached values (tests). */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /** Template options merged with defaults. */
    public static function template(): array
    {
        $stored = self::get('template', []);
        $options = array_intersect_key(is_array($stored) ? $stored : [], self::TEMPLATE) + self::TEMPLATE;
        if ($options['navbar'] === 'auto') {  // an option of the former "Clean" look
            $options['navbar'] = 'dark';
        }
        return $options;
    }

    /**
     * Validates template options from the form. Returns [options, errors].
     * @return array{array<string, mixed>, array<string, string>}
     */
    public static function validateTemplate(array $in): array
    {
        $out = self::TEMPLATE;
        $err = [];
        $out['container'] = ($in['container'] ?? '') === 'container-fluid' ? 'container-fluid' : 'container';
        $out['layout'] = ($in['layout'] ?? '') === 'sidebar' ? 'sidebar' : 'panels';
        $out['navbar'] = in_array($in['navbar'] ?? '', ['light', 'dark', 'primary'], true) ? $in['navbar'] : 'dark';
        $out['color_mode'] = in_array($in['color_mode'] ?? '', ['light', 'dark', 'auto'], true) ? $in['color_mode'] : 'light';
        foreach (['left_width', 'right_width'] as $name) {
            $width = (int) ($in[$name] ?? 3);
            $out[$name] = in_array($width, [2, 3, 4], true) ? $width : 3;
        }
        foreach (['show_left', 'show_right', 'navbar_sticky', 'show_sitename', 'show_copyright'] as $name) {
            $out[$name] = !empty($in[$name]);
        }
        $logo = trim((string) ($in['logo'] ?? ''));
        if ($logo !== '' && !Media::isImagePath($logo) && !preg_match('~^images/[A-Za-z0-9/_.-]+\.svg$~', $logo)) {
            $err['logo'] = t('PAGES_ERR_IMAGE');
        }
        $out['logo'] = $logo;
        return [$out, $err];
    }

    /** The site's default meta description in $lang ('' if none); stored as 'meta_description' {lang: text}. */
    public static function siteDescription(string $lang): string
    {
        $all = self::get('meta_description', []);
        return is_array($all) && is_string($all[$lang] ?? null) ? $all[$lang] : '';
    }

    /** Saves the custom CSS; the version it replaces goes to the history. */
    public static function saveCustomCss(string $css, string $reason = 'save'): void
    {
        $old = (string) self::get('custom_css', '');
        if ($css === $old) {
            return;
        }
        Db::get()->transaction(function () use ($css, $old, $reason) {
            Revisions::record('css', 0, ['date' => self::get('custom_css_modified'), 'content' => ['css' => $old]], $reason);
            self::set('custom_css', $css);
            self::set('custom_css_modified', Db::now());
        });
    }

    /** @return array{int, array<string, string>, list<string>} */
    public static function restoreCustomCss(array $content): array
    {
        self::saveCustomCss((string) ($content['css'] ?? ''), 'restore');
        return [0, [], []];
    }

    /** Custom CSS made safe to print inside <style>. */
    public static function customCss(): string
    {
        return (string) preg_replace('~</(style)~i', '<\\\\/$1', (string) self::get('custom_css', ''));
    }
}
