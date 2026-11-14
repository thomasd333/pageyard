<?php

namespace Pageyard\Site;

use Pageyard\Images;
use Pageyard\Media;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/** Output filters for content written in the editor. */
final class Html
{
    /**
     * Prepares stored HTML for the page. The editor stores links and images relative to the site
     * root ("images/a.jpg", "o-nas"); they get the root prefix so they work at any URL depth.
     * Then {script} tags are replaced with the output of the scripts (left as the scripts wrote it).
     */
    public static function content(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        return Scripts::render(self::resolveUrls(self::images($html), Uri::root()));
    }

    /**
     * Images in stored HTML load lazily and, when they are local, offer smaller sizes. An image with a
     * width set in the editor gets sizes for that width, never larger than twice it (sharp screens).
     */
    public static function images(string $html): string
    {
        return (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $m): string {
            $tag = $m[0];
            $add = '';
            if (!preg_match('/\sloading\s*=/i', $tag)) {
                $add .= ' loading="lazy"';
            }
            if (!preg_match('/\sdecoding\s*=/i', $tag)) {
                $add .= ' decoding="async"';
            }
            if (!preg_match('/\ssrcset\s*=/i', $tag) && preg_match('/\ssrc\s*=\s*(["\'])(images\/[^"\']+)\1/i', $tag, $src)) {
                $path = rawurldecode($src[2]);
                if (Media::isImagePath($path) && !str_starts_with($path, 'images/' . Images::THUMBS . '/')) {
                    $width = preg_match('/\swidth\s*=\s*["\']?(\d+)/i', $tag, $w) ? (int) $w[1] : 0;
                    $v = Images::variants($path, $width > 0 ? min(1600, $width * 2) : 1600);
                    $srcset = Images::srcset($v['set']);
                    if ($srcset !== '') {
                        $sizes = $width > 0 ? "(min-width: {$width}px) {$width}px, 100vw" : '(min-width: 1400px) 1320px, 100vw';
                        $add .= ' srcset="' . e($srcset) . '" sizes="' . e($sizes) . '"';
                    }
                    if ($width === 0 && $v['width'] > 0 && !preg_match('/\sheight\s*=/i', $tag)) {
                        $add .= ' width="' . $v['width'] . '" height="' . $v['height'] . '"';
                    }
                }
            }
            return (string) preg_replace('~\s*/?>$~', $add . '>', $tag);
        }, $html);
    }

    /** Prefixes $root to src/href/poster values that are relative (no scheme, not /, # or ?). */
    public static function resolveUrls(string $html, string $root): string
    {
        return (string) preg_replace_callback(
            '/\b(src|href|poster)\s*=\s*(["\'])(?![a-z][a-z0-9+.\-]*:|\/|#|\?|\2)(.*?)\2/i',
            fn ($m) => $m[1] . '=' . $m[2] . $root . $m[3] . $m[2],
            $html,
        );
    }
}
