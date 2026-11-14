<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Images in images/: uploads (into images/<folder>/<yyyy-mm>/ from editors, or into any folder from the
 * media manager), shrunk on upload (Images::fitWithin, config image_max), and the library functions of
 * the media manager. images/_thumbs/ holds generated thumbnails and is hidden from the library.
 */
final class Media
{
    /** Allowed types: extension => MIME type. No SVG (it can carry scripts). */
    public const TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif',
    ];
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Stores an uploaded image from $_FILES[...] and returns its path relative to the
     * site root (e.g. "images/pages/2026-11/photo.jpg"). Throws \RuntimeException with a user-facing message.
     *
     * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $file
     */
    public static function storeUpload(array $file, string $folder = 'pages', ?string $root = null): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $folder)) {
            throw new \InvalidArgumentException("Bad media folder: $folder");
        }
        return self::storeInto($file, "images/$folder/" . date('Y-m'), $root);
    }

    /**
     * Stores an uploaded image in $relDir (relative to the site root, inside images/, created if needed)
     * and returns its path. Images larger than image_max pixels are shrunk.
     */
    public static function storeInto(array $file, string $relDir, ?string $root = null): string
    {
        $root ??= PY_ROOT;
        $relDir = trim(str_replace('\\', '/', $relDir), '/');
        if (!preg_match('~^images(/[a-z0-9_-]+)*$~', $relDir) || str_starts_with($relDir . '/', 'images/' . Images::THUMBS . '/')) {
            throw new \InvalidArgumentException("Bad media folder: $relDir");
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException(t('MEDIA_ERR_SIZE', self::maxSizeMb()));
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(t('MEDIA_ERR_UPLOAD', $error));
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new \RuntimeException(t('MEDIA_ERR_UPLOAD', 0));
        }
        if ((int) ($file['size'] ?? filesize($tmp)) > min(self::MAX_BYTES, self::maxSizeMb() * 1024 * 1024)) {
            throw new \RuntimeException(t('MEDIA_ERR_SIZE', self::maxSizeMb()));
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$ext]) || self::TYPES[$ext] !== $mime || @getimagesize($tmp) === false) {
            throw new \RuntimeException(t('MEDIA_ERR_TYPE', implode(', ', array_unique(array_keys(self::TYPES)))));
        }
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $dir = "$root/$relDir";
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(t('MEDIA_ERR_WRITE', $relDir));
        }
        $base = Str::slug(pathinfo($name, PATHINFO_FILENAME), 80) ?: 'image';
        $target = "$base.$ext";
        for ($n = 2; file_exists("$dir/$target"); $n++) {
            $target = "$base-$n.$ext";
        }
        $ok = PHP_SAPI === 'cli' ? copy($tmp, "$dir/$target") : move_uploaded_file($tmp, "$dir/$target");
        if (!$ok) {
            throw new \RuntimeException(t('MEDIA_ERR_WRITE', $relDir));
        }
        @chmod("$dir/$target", 0644);
        Images::fitWithin("$dir/$target", (int) Config::get('image_max', 2000), (int) Config::get('image_quality', 85));
        return "$relDir/$target";
    }

    // ---- Library (media manager) ----

    /** Folder inside images/ (not _thumbs) as a clean relative path, or null. */
    public static function folder(string $relDir, ?string $root = null): ?string
    {
        $root ??= PY_ROOT;
        $relDir = trim(str_replace('\\', '/', $relDir), '/');
        if (!preg_match('~^images(/[A-Za-z0-9_.-]+)*$~', $relDir) || str_contains($relDir, '..')
            || str_starts_with($relDir . '/', 'images/' . Images::THUMBS . '/') || !is_dir("$root/$relDir")) {
            return null;
        }
        return $relDir;
    }

    /**
     * All folders below images/ (images itself first), without _thumbs.
     * @return list<string>
     */
    public static function folders(?string $root = null): array
    {
        $root ??= PY_ROOT;
        $out = ['images'];
        $walk = function (string $rel) use (&$walk, &$out, $root): void {
            $dirs = glob("$root/$rel/*", GLOB_ONLYDIR) ?: [];
            natcasesort($dirs);
            foreach ($dirs as $dir) {
                $name = basename($dir);
                if ($rel === 'images' && $name === Images::THUMBS) {
                    continue;
                }
                $out[] = "$rel/$name";
                $walk("$rel/$name");
            }
        };
        $walk('images');
        return $out;
    }

    /**
     * Folders a gallery can show, for the editor's dialog: path below images/ and how many images it holds.
     * @return list<array{path: string, images: int}>
     */
    public static function galleryFolders(?string $root = null): array
    {
        $root ??= PY_ROOT;
        $out = [];
        foreach (self::folders($root) as $folder) {
            if ($folder === 'images' || self::folder($folder, $root) === null) {
                continue;
            }
            $images = 0;
            foreach (glob("$root/$folder/*") ?: [] as $file) {
                if (is_file($file) && self::isImagePath("$folder/" . basename($file))) {
                    $images++;
                }
            }
            $out[] = ['path' => substr($folder, 7), 'images' => $images];
        }
        return $out;
    }

    /**
     * Images in one folder, newest first.
     * @return list<array{path: string, name: string, size: int, width: int, height: int, modified: int}>
     */
    public static function files(string $relDir, ?string $root = null): array
    {
        $root ??= PY_ROOT;
        $out = [];
        foreach (glob("$root/$relDir/*") ?: [] as $file) {
            $name = basename($file);
            if (!is_file($file) || !self::isImagePath("$relDir/$name")) {
                continue;
            }
            $size = @getimagesize($file);
            $out[] = ['path' => "$relDir/$name", 'name' => $name, 'size' => (int) filesize($file),
                'width' => $size[0] ?? 0, 'height' => $size[1] ?? 0, 'modified' => (int) filemtime($file)];
        }
        usort($out, fn ($a, $b) => [$b['modified'], $a['name']] <=> [$a['modified'], $b['name']]);
        return $out;
    }

    /** The folder holding the most recently changed image (where one usually continues), or images. */
    public static function newestFolder(?string $root = null): string
    {
        $root ??= PY_ROOT;
        $best = ['images', 0];
        foreach (self::folders($root) as $folder) {
            foreach (glob("$root/$folder/*.{jpg,jpeg,png,gif,webp,avif}", GLOB_BRACE) ?: [] as $file) {
                $time = (int) filemtime($file);
                if ($time > $best[1]) {
                    $best = [$folder, $time];
                }
            }
        }
        return $best[0];
    }

    /** Creates a subfolder (name slugged); returns its path. */
    public static function createFolder(string $parent, string $name, ?string $root = null): string
    {
        $root ??= PY_ROOT;
        $parent = self::folder($parent, $root) ?? throw new \RuntimeException(t('ERR_NOT_FOUND'));
        $slug = Str::slug($name, 60);
        if ($slug === '' || ($parent === 'images' && $slug === Images::THUMBS)) {
            throw new \RuntimeException(t('MEDIA_ERR_FOLDER_NAME'));
        }
        if (!is_dir("$root/$parent/$slug") && !mkdir("$root/$parent/$slug", 0775)) {
            throw new \RuntimeException(t('MEDIA_ERR_WRITE', "$parent/$slug"));
        }
        return "$parent/$slug";
    }

    /** Deletes images (and their thumbnails). Returns how many. */
    public static function deleteFiles(array $paths, ?string $root = null): int
    {
        $root ??= PY_ROOT;
        $count = 0;
        foreach ($paths as $path) {
            $path = (string) $path;
            if (!self::isImagePath($path) || self::folder(dirname($path), $root) === null || !is_file("$root/$path")) {
                continue;
            }
            if (unlink("$root/$path")) {
                Images::deleteThumbs($path);
                Log::info("Image deleted: $path");
                $count++;
            }
        }
        return $count;
    }

    /** Deletes an empty folder (not images/ itself). */
    public static function deleteFolder(string $relDir, ?string $root = null): bool
    {
        $root ??= PY_ROOT;
        $relDir = self::folder($relDir, $root);
        if ($relDir === null || $relDir === 'images' || (glob("$root/$relDir/{,.}[!.]*", GLOB_BRACE) ?: []) !== []) {
            return false;
        }
        return rmdir("$root/$relDir");
    }

    /**
     * Where images are used: path => list of descriptions ("Page: About us", "Block: Footer", ...).
     * Looks at page thumbnails and texts, category descriptions, HTML blocks and the template settings.
     * @param list<string> $paths
     * @return array<string, list<string>>
     */
    public static function usage(array $paths): array
    {
        $out = array_fill_keys($paths, []);
        if (!$paths) {
            return $out;
        }
        $db = Db::get();
        $sources = [];
        foreach ($db->fetchAll('SELECT p.image, t.title, t.intro, t.body FROM #__pages p JOIN ' . Pages::T . ' t ON t.page_id = p.id') as $r) {
            $sources[] = [t('MEDIA_USED_PAGE', $r['title']), $r['image'] . ' ' . $r['intro'] . ' ' . $r['body']];
        }
        foreach ($db->fetchAll('SELECT title, description FROM ' . Categories::T) as $r) {
            $sources[] = [t('MEDIA_USED_CATEGORY', $r['title']), (string) $r['description']];
        }
        foreach ($db->fetchAll('SELECT b.title, t.content FROM #__blocks b JOIN ' . Blocks::T . ' t ON t.block_id = b.id') as $r) {
            $sources[] = [t('MEDIA_USED_BLOCK', $r['title']), (string) $r['content']];
        }
        foreach ($db->fetchAll("SELECT title, image FROM #__blocks WHERE image <> ''") as $r) {
            $sources[] = [t('MEDIA_USED_BLOCK', $r['title']), (string) $r['image']];
        }
        foreach ($db->fetchAll('SELECT name, value FROM #__settings') as $r) {
            $sources[] = [t('MEDIA_USED_TEMPLATE'), (string) $r['value']];
        }
        foreach ($paths as $path) {
            foreach ($sources as [$label, $text]) {
                // Stored as images/... in content; JSON settings escape slashes only without UNESCAPED_SLASHES.
                if ((str_contains($text, $path) || str_contains($text, str_replace('/', '\/', $path))) && !in_array($label, $out[$path], true)) {
                    $out[$path][] = $label;
                }
            }
        }
        return $out;
    }

    /** Upload limit from php.ini (and our own cap), in MB. */
    public static function maxSizeMb(): int
    {
        $bytes = static function (string $v): int {
            $n = (int) $v;
            return match (strtolower(substr(trim($v), -1))) {
                'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
            };
        };
        $limits = array_filter([self::MAX_BYTES, $bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);
        return max(1, intdiv(min($limits), 1024 * 1024));
    }

    /** True for a path we would have stored ourselves (used to validate posted image fields). */
    public static function isImagePath(string $path): bool
    {
        return (bool) preg_match('~^images/[A-Za-z0-9/_.-]+\.(' . implode('|', array_keys(self::TYPES)) . ')$~i', $path)
            && !str_contains($path, '..');
    }
}
