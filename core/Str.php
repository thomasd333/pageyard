<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/** String helpers. */
final class Str
{
    /** Letters that Latin-ASCII transliteration misses or that iconv mangles. */
    private const MAP = [
        'ł' => 'l', 'Ł' => 'l', 'ß' => 'ss', 'æ' => 'ae', 'Æ' => 'ae', 'ø' => 'o', 'Ø' => 'o', 'đ' => 'd', 'Đ' => 'd',
        'þ' => 'th', 'Þ' => 'th', 'œ' => 'oe', 'Œ' => 'oe', 'ı' => 'i',
    ];

    /** "Zażółć gęślą jaźń!" -> "zazolc-gesla-jazn". Empty when nothing usable is left. */
    public static function slug(string $text, int $max = 190): string
    {
        $text = strtr($text, self::MAP);
        if (function_exists('transliterator_transliterate')) {
            $text = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            $text = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text));
        }
        $text = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        if (strlen($text) > $max) {
            $text = rtrim(substr($text, 0, $max), '-');
        }
        return $text;
    }

    /** Plain-text excerpt of HTML, cut on a word boundary. */
    public static function excerpt(?string $html, int $length = 160): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space > $length / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
    }
}
