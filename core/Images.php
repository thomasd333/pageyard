<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/**
 * Image processing with GD: shrinking uploads, EXIF orientation of photos, thumbnails.
 * Without GD (or for a format GD can't write) everything falls back to the original files.
 *
 * Thumbnails live in images/_thumbs/<width>/<path below images/>, made on first use and remade when
 * the original is newer. They are only made when the original is wider than the requested width.
 */
final class Images
{
    public const THUMBS = '_thumbs';
    /** Widths offered to browsers in srcset, as far as the image and the place it is shown in need them. */
    public const WIDTHS = [480, 800, 1200, 1600];
    /** Seconds one request may spend making thumbnails; the originals stand in, the next request carries on. */
    private const BUDGET = 8.0;

    private static float $spent = 0.0;

    /** Overridable for tests; default PY_ROOT. */
    public static ?string $root = null;

    public static function root(): string
    {
        return self::$root ?? PY_ROOT;
    }

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor');
    }

    /**
     * Shrinks an image file in place so that its longer side doesn't exceed $max pixels, and turns JPEG
     * photos upright (EXIF orientation). GIFs are left alone (they may be animated). Returns true when
     * the file was rewritten.
     *
     * A panorama (longer than 2:1) keeps its shorter side at $max / 2 instead, with the longer one up to
     * 3 x $max: a banner scales it by its height, so the plain limit would leave it blurred.
     */
    public static function fitWithin(string $file, int $max, int $quality = 85): bool
    {
        if (!self::available() || $max <= 0) {
            return false;
        }
        $info = @getimagesize($file);
        if ($info === false || $info[2] === IMAGETYPE_GIF || !self::canWrite($info[2])) {
            return false;
        }
        $orientation = $info[2] === IMAGETYPE_JPEG ? self::orientation($file) : 1;
        [$w, $h] = in_array($orientation, [5, 6, 7, 8], true) ? [$info[1], $info[0]] : [$info[0], $info[1]];
        $long = max($w, $h);
        $scale = min(1, max($max / $long, min($max / 2 / min($w, $h), $max * 3 / $long)));
        if ($scale >= 1 && $orientation === 1) {
            return false;
        }
        $image = self::load($file, $info[2]);
        if ($image === null) {
            return false;
        }
        $image = self::orient($image, $orientation);
        if ($scale < 1) {
            $image = self::resized($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        }
        return self::save($image, $file, $info[2], $quality);
    }

    /**
     * Path (relative to the site root) of a version of $path at most $width pixels wide: a cached
     * thumbnail, or $path itself when it is narrower already, isn't an image we handle, or GD is missing.
     */
    public static function thumb(string $path, int $width): string
    {
        if ($path === '' || !self::available() || !Media::isImagePath($path) || !str_starts_with($path, 'images/')) {
            return $path;
        }
        $source = self::root() . '/' . $path;
        $webp = self::webpThumbs() && !preg_match('/\.(gif|webp|avif)$/i', $path);
        $target = 'images/' . self::THUMBS . "/$width/" . substr($path, 7) . ($webp ? '.webp' : '');
        $targetFile = self::root() . '/' . $target;
        if (is_file($targetFile) && @filemtime($targetFile) >= @filemtime($source)) {
            return $target;
        }
        if (self::$spent >= self::BUDGET) {
            return $path;
        }
        $started = microtime(true);
        $target = self::makeThumb($path, $source, $target, $targetFile, $width, $webp);
        self::$spent += microtime(true) - $started;
        return $target;
    }

    /** Makes a thumbnail; returns its path, or $path when none is needed or possible. */
    private static function makeThumb(string $path, string $source, string $target, string $targetFile, int $width, bool $webp): string
    {
        $info = @getimagesize($source);
        if ($info === false || $info[0] <= $width || !self::canWrite($info[2])) {
            return $path;
        }
        $image = self::load($source, $info[2]);
        if ($image === null) {
            return $path;
        }
        $orientation = $info[2] === IMAGETYPE_JPEG ? self::orientation($source) : 1;
        $image = self::orient($image, $orientation);
        $w = imagesx($image);
        $h = imagesy($image);
        if ($w > $width) {
            $image = self::resized($image, $width, max(1, (int) round($h * $width / $w)));
        }
        $dir = dirname($targetFile);
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
            || !self::save($image, $targetFile, $webp ? IMAGETYPE_WEBP : $info[2], (int) Config::get('image_quality', 85))) {
            return $path;
        }
        return $target;
    }

    /** Thumbnails as WebP (option in Global Configuration), if PHP can write it. Originals stay as they are. */
    public static function webpThumbs(): bool
    {
        return Config::get('image_webp', false) && self::webpAvailable();
    }

    public static function webpAvailable(): bool
    {
        return self::available() && function_exists('imagewebp');
    }

    /**
     * What a browser needs to pick a size: thumbnails up to $largest wide (and no wider than the image).
     * Entries are width => path; without GD or for a small image only the original is listed.
     * @return array{src: string, set: array<int, string>, width: int, height: int}
     */
    public static function variants(string $path, int $largest): array
    {
        $file = self::root() . '/' . $path;
        $info = is_file($file) ? @getimagesize($file) : false;
        $width = $info[0] ?? 0;
        $height = $info[1] ?? 0;
        $set = [];
        foreach (array_unique([...array_filter(self::WIDTHS, fn (int $w) => $w < $largest), $largest]) as $w) {
            if ($w < $width) {
                $thumb = self::thumb($path, $w);
                if ($thumb !== $path) {
                    $set[$w] = $thumb;
                }
            }
        }
        // The image itself is the top step when it is narrower than the place it fills.
        if ($width > 0 && $width <= $largest) {
            $set[$width] = $path;
        }
        return ['src' => $set[$largest] ?? ($set ? end($set) : $path), 'set' => $set, 'width' => $width, 'height' => $height];
    }

    /** "<root>a.jpg 480w, <root>b.jpg 800w", or '' when there is nothing to choose from. */
    public static function srcset(array $set): string
    {
        if (count($set) < 2) {
            return '';
        }
        $parts = [];
        foreach ($set as $width => $path) {
            $parts[] = Uri::root() . implode('/', array_map('rawurlencode', explode('/', $path))) . " {$width}w";
        }
        return implode(', ', $parts);
    }

    /**
     * Attributes of an <img> for an image shown at the given sizes (escaped, starts with a space).
     * width and height let the page keep its layout while the image loads.
     */
    public static function attributes(string $path, int $largest, string $sizes): string
    {
        $v = self::variants($path, $largest);
        $srcset = self::srcset($v['set']);
        return ' src="' . e(Uri::root() . $v['src']) . '"'
            . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="' . e($sizes) . '"' : '')
            . ($v['width'] > 0 ? ' width="' . $v['width'] . '" height="' . $v['height'] . '"' : '');
    }

    /** Deletes the thumbnails of an image (all widths). */
    public static function deleteThumbs(string $path): void
    {
        if (!str_starts_with($path, 'images/')) {
            return;
        }
        foreach (glob(self::root() . '/images/' . self::THUMBS . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $file = $dir . '/' . substr($path, 7);
            foreach ([$file, "$file.webp"] as $thumb) {
                if (is_file($thumb)) {
                    @unlink($thumb);
                }
            }
        }
    }

    private static function canWrite(int $type): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagejpeg'),
            IMAGETYPE_PNG  => function_exists('imagepng'),
            IMAGETYPE_GIF  => function_exists('imagegif'),
            IMAGETYPE_WEBP => function_exists('imagewebp'),
            IMAGETYPE_AVIF => function_exists('imageavif') && function_exists('imagecreatefromavif'),
            default        => false,
        };
    }

    private static function load(string $file, int $type): ?\GdImage
    {
        try {
            $image = match ($type) {
                IMAGETYPE_JPEG => imagecreatefromjpeg($file),
                IMAGETYPE_PNG  => imagecreatefrompng($file),
                IMAGETYPE_GIF  => imagecreatefromgif($file),
                IMAGETYPE_WEBP => imagecreatefromwebp($file),
                IMAGETYPE_AVIF => imagecreatefromavif($file),
                default        => false,
            };
        } catch (\Throwable $e) {
            Log::error("Image $file could not be read: " . $e->getMessage());
            return null;
        }
        return $image instanceof \GdImage ? $image : null;
    }

    private static function save(\GdImage $image, string $file, int $type, int $quality): bool
    {
        $quality = max(30, min(100, $quality));
        $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        try {
            $ok = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($image, $tmp, $quality),
                IMAGETYPE_PNG  => imagepng($image, $tmp, 6),
                IMAGETYPE_GIF  => imagegif($image, $tmp),
                IMAGETYPE_WEBP => imagewebp($image, $tmp, $quality),
                IMAGETYPE_AVIF => imageavif($image, $tmp, max(0, $quality - 25)),
                default        => false,
            };
            if ($ok && rename($tmp, $file)) {
                @chmod($file, 0644);
                return true;
            }
        } catch (\Throwable $e) {
            Log::error("Image $file could not be written: " . $e->getMessage());
        }
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        return false;
    }

    /** Resampled copy keeping transparency (PNG, WebP, AVIF, GIF). */
    private static function resized(\GdImage $image, int $w, int $h): \GdImage
    {
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image));
        return $out;
    }

    /** EXIF orientation of a JPEG (1 = upright; also when exif is not installed). */
    private static function orientation(string $file): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        try {
            $exif = @exif_read_data($file);
        } catch (\Throwable) {
            return 1;
        }
        $o = (int) ($exif['Orientation'] ?? 1);
        return $o >= 1 && $o <= 8 ? $o : 1;
    }

    /** Applies EXIF orientation 2-8: rotate (GD angles are counter-clockwise), then mirror for 2, 4, 5, 7. */
    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated instanceof \GdImage) {
                $image = $rotated;
            }
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        return $image;
    }
}
