<?php

namespace Pageyard\Site;

use Pageyard\Images;
use Pageyard\Log;
use Pageyard\Media;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/**
 * Image gallery from a folder of the media library, embedded with {script built-in/gallery folder=name}
 * or the Gallery button in the editor.
 * A grid of thumbnails; each links to the full image, which media/site/gallery.js opens in a viewer
 * (without JavaScript the link simply opens the image).
 */
final class Gallery
{
    public const THUMB = 480;
    private const COLUMNS = [
        2 => 'row-cols-1 row-cols-sm-2',
        3 => 'row-cols-2 row-cols-md-3',
        4 => 'row-cols-2 row-cols-md-3 row-cols-lg-4',
        5 => 'row-cols-2 row-cols-md-3 row-cols-lg-5',
        6 => 'row-cols-2 row-cols-md-4 row-cols-lg-6',
    ];

    private static bool $scriptDone = false;

    /** @param array<string, string|true> $params folder, columns, order (name|newest), limit, captions */
    public static function render(array $params): string
    {
        $folder = is_string($params['folder'] ?? null) ? trim($params['folder'], " /\\") : '';
        $relDir = Media::folder(str_starts_with($folder . '/', 'images/') ? $folder : "images/$folder", Images::root());
        if ($folder === '' || $relDir === null) {
            Log::error("Gallery: no folder \"$folder\" in images/.");
            return '<!-- gallery: folder not found -->';
        }
        $files = Media::files($relDir, Images::root());
        if (($params['order'] ?? 'name') !== 'newest') {
            usort($files, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        }
        $limit = (int) ($params['limit'] ?? 0);
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }
        if (!$files) {
            return '';
        }
        $columns = self::COLUMNS[(int) ($params['columns'] ?? 4)] ?? self::COLUMNS[4];
        $captions = isset($params['captions']);
        $root = Uri::root();

        $html = '<div class="py-gallery row ' . $columns . ' g-2 mb-3" data-py-gallery data-close="' . e(t('GALLERY_CLOSE'))
            . '" data-prev="' . e(t('GALLERY_PREV')) . '" data-next="' . e(t('GALLERY_NEXT')) . '">';
        foreach ($files as $file) {
            $text = self::caption($file['name']);
            $thumb = Images::thumb($file['path'], self::THUMB);
            $html .= '<figure class="col m-0"><a class="d-block ratio ratio-1x1" href="' . e($root . $file['path']) . '" title="' . e($text) . '">'
                . '<img class="object-fit-cover rounded" src="' . e($root . $thumb) . '" alt="' . e($text) . '" loading="lazy" decoding="async"></a>'
                . ($captions ? '<figcaption class="small text-body-secondary mt-1">' . e($text) . '</figcaption>' : '')
                . '</figure>';
        }
        $html .= '</div>';
        if (!self::$scriptDone) {
            self::$scriptDone = true;
            $html .= '<script src="' . e(Uri::media('site/gallery.js')) . '?v=' . e(PY_VERSION) . '" defer></script>';
        }
        return $html;
    }

    /** "moj-wyjazd_2.jpg" -> "Moj wyjazd 2". */
    public static function caption(string $name): string
    {
        $text = trim((string) preg_replace('/[-_\s]+/', ' ', pathinfo($name, PATHINFO_FILENAME)));
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
