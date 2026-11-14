<?php

use Pageyard\Config;
use Pageyard\Images;
use Pageyard\Log;
use Pageyard\Media;
use Pageyard\Pages;
use Pageyard\Site\Gallery;
use Pageyard\Site\Html;
use Pageyard\Site\Router;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

/** Writes a test image ($type: jpg|png|gif) of $w x $h and returns the file path. */
function make_image(string $file, int $w, int $h, string $type = 'jpg'): string
{
    @mkdir(dirname($file), 0777, true);
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, (int) ($w / 2), $h, imagecolorallocate($im, 200, 30, 30));
    if ($type === 'png') {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, (int) ($w / 2), 0, $w, $h, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagepng($im, $file);
    } elseif ($type === 'gif') {
        imagegif($im, $file);
    } else {
        imagejpeg($im, $file, 90);
    }
    return $file;
}

/** Adds an EXIF APP1 segment with the given Orientation right after the JPEG's SOI marker. */
function with_orientation(string $file, int $orientation): void
{
    $tiff = 'II*' . "\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
    $app1 = "Exif\0\0" . $tiff;
    $jpeg = (string) file_get_contents($file);
    file_put_contents($file, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));
}

function images_root(): string
{
    $root = temp_root();
    Images::$root = $root;
    Log::$file = "$root/data/test.log";
    Config::set(['image_max' => 2000, 'image_quality' => 85]);
    return $root;
}

return [
    'images: sizes for browsers' => function () {
        $root = temp_root();
        Images::$root = $root;
        Config::set(['image_quality' => 80, 'live_site' => 'http://example.com/cms']);
        make_image("$root/images/big.jpg", 2000, 1000);
        make_image("$root/images/mid.jpg", 1000, 500);
        make_image("$root/images/small.jpg", 300, 150);

        $v = Images::variants('images/big.jpg', 1600);
        same([480, 800, 1200, 1600], array_keys($v['set']));
        same('images/_thumbs/1600/big.jpg', $v['src']);
        same([2000, 1000], [$v['width'], $v['height']]);
        same([480, 800], array_keys(Images::variants('images/big.jpg', 800)['set']), 'no larger than the place needs');

        $v = Images::variants('images/mid.jpg', 1600);
        same([480, 800, 1000], array_keys($v['set']), 'the original is the top step');
        same('images/mid.jpg', $v['set'][1000]);
        same('images/mid.jpg', $v['src']);

        $attributes = Images::attributes('images/small.jpg', 800, '100vw');
        same(' src="/cms/images/small.jpg" width="300" height="150"', $attributes, 'nothing to choose from');
        same(' src="/cms/images/none.jpg"', Images::attributes('images/none.jpg', 800, '100vw'), 'a missing file');

        $attributes = Images::attributes('images/big.jpg', 800, '50vw');
        check(str_contains($attributes, 'srcset="/cms/images/_thumbs/480/big.jpg 480w, /cms/images/_thumbs/800/big.jpg 800w" sizes="50vw"'), $attributes);
        check(str_contains($attributes, 'width="2000" height="1000"'));
        Images::$root = null;
    },

    'images: WebP thumbnails, originals untouched, deleted together' => function () {
        if (!Images::webpAvailable()) {
            return;
        }
        $root = temp_root();
        Images::$root = $root;
        Config::set(['image_quality' => 80, 'image_webp' => true]);
        make_image("$root/images/a.jpg", 1000, 500);
        make_image("$root/images/b.gif", 1000, 500, 'gif');
        same('images/_thumbs/400/a.jpg.webp', Images::thumb('images/a.jpg', 400));
        same(IMAGETYPE_WEBP, getimagesize("$root/images/_thumbs/400/a.jpg.webp")[2]);
        same(IMAGETYPE_JPEG, getimagesize("$root/images/a.jpg")[2]);
        same('images/_thumbs/400/b.gif', Images::thumb('images/b.gif', 400), 'not GIFs');
        Config::set(['image_quality' => 80, 'image_webp' => false]);
        same('images/_thumbs/400/a.jpg', Images::thumb('images/a.jpg', 400), 'switching off goes back to the old format');
        Images::deleteThumbs('images/a.jpg');
        check(!is_file("$root/images/_thumbs/400/a.jpg.webp") && !is_file("$root/images/_thumbs/400/a.jpg"));
        Images::$root = null;
    },

    'images: in stored content' => function () {
        $root = temp_root();
        Images::$root = $root;
        Config::set(['image_quality' => 80, 'live_site' => 'http://example.com/cms']);
        make_image("$root/images/big.jpg", 2000, 1000);
        make_image("$root/images/small.jpg", 300, 150);

        same('<p><img src="https://x.pl/a.jpg" loading="lazy" decoding="async"></p>', Html::images('<p><img src="https://x.pl/a.jpg"></p>'), 'not ours');
        same('<img src="images/none.jpg" loading="lazy" decoding="async">', Html::images('<img src="images/none.jpg" />'), 'a missing file');
        same('<img src="a.jpg" loading="eager" decoding="sync">', Html::images('<img src="a.jpg" loading="eager" decoding="sync">'), 'its own settings stay');
        same('<img src="images/x/../../data.jpg" loading="lazy" decoding="async">', Html::images('<img src="images/x/../../data.jpg">'));
        same('<img src="images/_thumbs/480/big.jpg" loading="lazy" decoding="async">', Html::images('<img src="images/_thumbs/480/big.jpg">'), 'a thumbnail stays');
        same('<img src="images/small.jpg" loading="lazy" decoding="async" width="300" height="150">', Html::images('<img src="images/small.jpg">'));

        $tag = Html::images('<img alt="x" src="images/big.jpg" width="300" height="150">');
        check(str_contains($tag, 'srcset="/cms/images/_thumbs/480/big.jpg 480w, /cms/images/_thumbs/600/big.jpg 600w" sizes="(min-width: 300px) 300px, 100vw"'), $tag);
        check(substr_count($tag, 'width=') === 1, 'its width stays the only one');

        $tag = Html::images("<img src='images/big.jpg'>");
        check(str_contains($tag, '/cms/images/_thumbs/1600/big.jpg 1600w'), $tag);
        check(str_contains($tag, 'width="2000" height="1000"'));
        check(str_contains(Html::content('<img src="images/big.jpg">'), 'src="/cms/images/big.jpg"'), 'the plain address still works');
        Images::$root = null;
    },

    'gallery: folders for the editor\'s dialog' => function () {
        $root = temp_root();
        mkdir("$root/images/trip/day-1", 0777, true);
        mkdir("$root/images/empty");
        mkdir("$root/images/_thumbs/480", 0777, true);
        mkdir("$root/images/with space");
        foreach (['root.png', 'trip/a.png', 'trip/b.jpg', 'trip/notes.txt', 'trip/day-1/c.png'] as $file) {
            file_put_contents("$root/images/$file", 'x');
        }
        same([
            ['path' => 'empty', 'images' => 0],
            ['path' => 'trip', 'images' => 2],
            ['path' => 'trip/day-1', 'images' => 1],
        ], Media::galleryFolders($root));
    },

    'gallery: thumbnails of a folder, names, options, bad folders' => function () {
        $root = temp_root();
        Images::$root = $root;
        mkdir("$root/images/trip", 0777, true);
        foreach (['moj-wyjazd_2.png' => 900, 'moj-wyjazd_10.png' => 900, 'a-small.png' => 100] as $name => $width) {
            $image = imagecreatetruecolor($width, 50);
            imagepng($image, "$root/images/trip/$name");
        }
        file_put_contents("$root/images/trip/notes.txt", 'x');

        $html = Gallery::render(['folder' => 'trip', 'columns' => '3']);
        check(str_contains($html, 'row-cols-md-3'));
        same(3, substr_count($html, '<figure'), 'images only');
        check(str_contains($html, 'images/_thumbs/480/trip/moj-wyjazd_2.png'), 'thumbnail');
        check(str_contains($html, 'href="/images/trip/moj-wyjazd_2.png"') || str_contains($html, 'images/trip/moj-wyjazd_2.png"'), 'full image link');
        check(str_contains($html, 'src="' . Uri::root() . 'images/trip/a-small.png"'), 'a small image stays itself');
        check(is_file("$root/images/_thumbs/480/trip/moj-wyjazd_10.png"));
        check(strpos($html, 'wyjazd_2') < strpos($html, 'wyjazd_10'), 'natural order');
        check(!str_contains($html, 'figcaption'));
        check(str_contains(Gallery::render(['folder' => 'images/trip/', 'captions' => true]), '<figcaption class="small text-body-secondary mt-1">Moj wyjazd 2</figcaption>'));
        same(1, substr_count(Gallery::render(['folder' => 'trip', 'limit' => '1']), '<figure'));
        same(3, substr_count(Gallery::render(['folder' => 'trip', 'columns' => '99']), '<figure'), 'unknown columns');

        foreach (['', '../data', 'nothing', '_thumbs', 'trip/../..'] as $bad) {
            same('<!-- gallery: folder not found -->', Gallery::render(['folder' => $bad]), "folder: $bad");
        }
        same('<!-- gallery: folder not found -->', Gallery::render([]));
        mkdir("$root/images/empty");
        same('', Gallery::render(['folder' => 'empty']));
        Images::$root = null;
    },

    'images: uploads are shrunk and turned upright' => function () {
        if (!Images::available()) {
            return 'skip';
        }
        $root = images_root();
        $big = make_image("$root/a.jpg", 3000, 1500);
        same(true, Images::fitWithin($big, 2000));
        same([2000, 1000], array_slice(getimagesize($big), 0, 2));
        same(false, Images::fitWithin($big, 2000), 'already small enough');

        $tall = make_image("$root/tall.jpg", 1500, 3000);
        Images::fitWithin($tall, 2000);
        same([1000, 2000], array_slice(getimagesize($tall), 0, 2));

        // Panoramas keep their height (a banner scales them by it), within a limit on the length.
        $wide = make_image("$root/wide.jpg", 8192, 1856);
        Images::fitWithin($wide, 2000);
        same([4414, 1000], array_slice(getimagesize($wide), 0, 2));
        $strip = make_image("$root/strip.jpg", 8000, 800);
        Images::fitWithin($strip, 2000);
        same([6000, 600], array_slice(getimagesize($strip), 0, 2));
        $small = make_image("$root/small.jpg", 2400, 600);
        same(false, Images::fitWithin($small, 2000), 'low already: stays as it is');

        $png = make_image("$root/b.png", 2400, 1200, 'png');
        Images::fitWithin($png, 1200);
        $im = imagecreatefrompng($png);
        same([1200, 600, 127], [imagesx($im), imagesy($im), (imagecolorat($im, 1100, 100) >> 24) & 0x7F], 'transparency kept');

        $gif = make_image("$root/c.gif", 3000, 100, 'gif');
        same(false, Images::fitWithin($gif, 2000), 'GIFs may be animated: left alone');

        if (function_exists('exif_read_data')) {
            $photo = make_image("$root/d.jpg", 400, 200);
            with_orientation($photo, 6);
            same(6, exif_read_data($photo)['Orientation']);
            same(true, Images::fitWithin($photo, 2000), 'rewritten although small');
            same([200, 400], array_slice(getimagesize($photo), 0, 2), 'rotated upright');
            $im = imagecreatefromjpeg($photo);
            same(true, (imagecolorat($im, 100, 50) >> 16 & 0xFF) > 150, 'the red left half is now the top');
        }
        Images::$root = null;
    },

    'images: thumbnails on demand' => function () {
        if (!Images::available()) {
            return 'skip';
        }
        $root = images_root();
        make_image("$root/images/pages/2026-11/wide.jpg", 1000, 500);
        make_image("$root/images/pages/2026-11/small.jpg", 200, 100);
        $thumb = Images::thumb('images/pages/2026-11/wide.jpg', 320);
        same('images/_thumbs/320/pages/2026-11/wide.jpg', $thumb);
        same([320, 160], array_slice(getimagesize("$root/$thumb"), 0, 2));
        $time = filemtime("$root/$thumb");
        same($thumb, Images::thumb('images/pages/2026-11/wide.jpg', 320), 'cached');
        same($time, filemtime("$root/$thumb"));
        same('images/pages/2026-11/small.jpg', Images::thumb('images/pages/2026-11/small.jpg', 320), 'narrower: the original');
        same('images/x/missing.jpg', Images::thumb('images/x/missing.jpg', 320));
        same('../x.jpg', Images::thumb('../x.jpg', 320));
        Images::deleteThumbs('images/pages/2026-11/wide.jpg');
        same(false, is_file("$root/$thumb"));
        Images::$root = null;
    },

    'media: library folders, files and deleting' => function () {
        $root = images_root();
        make_image("$root/images/pages/2026-11/a.jpg", 50, 50);
        make_image("$root/images/site/logo.png", 50, 50, 'png');
        make_image("$root/images/_thumbs/320/site/logo.png", 50, 50, 'png');
        file_put_contents("$root/images/site/notes.txt", 'x');
        same(['images', 'images/pages', 'images/pages/2026-11', 'images/site'], Media::folders($root), 'no _thumbs');
        same(['images/site/logo.png'], array_column(Media::files('images/site', $root), 'path'), 'images only');
        same(null, Media::folder('images/_thumbs/320', $root));
        same(null, Media::folder('images/../data', $root));
        same('images/site', Media::folder('/images/site/', $root));

        same('images/site/galeria-2026', Media::createFolder('images/site', 'Galeria 2026!', $root));
        same('images/thumbs', Media::createFolder('images', '_thumbs', $root), 'the slug drops the underscore: _thumbs stays ours');
        throws(fn () => Media::createFolder('images', '!!!', $root), RuntimeException::class);
        Media::deleteFolder('images/thumbs', $root);
        same(false, Media::deleteFolder('images/site', $root), 'not empty');
        same(false, Media::deleteFolder('images', $root));
        same(true, Media::deleteFolder('images/site/galeria-2026', $root));

        same(1, Media::deleteFiles(['images/site/logo.png', 'images/site/notes.txt', 'data/x.jpg', 'images/../a.jpg'], $root));
        same(false, is_file("$root/images/_thumbs/320/site/logo.png"), 'thumbnails go too');
        Images::$root = null;
    },

    'media: stored uploads land in the chosen folder and are shrunk' => function () {
        if (!Images::available()) {
            return 'skip';
        }
        $root = images_root();
        mkdir("$root/images/site", 0777, true);
        $tmp = make_image(sys_get_temp_dir() . '/py-upload-' . bin2hex(random_bytes(3)) . '.jpg', 2600, 1300);
        $path = Media::storeInto(['name' => 'Wielkie Zdjęcie.JPG', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], 'images/site', $root);
        same('images/site/wielkie-zdjecie.jpg', $path);
        same([2000, 1000], array_slice(getimagesize("$root/$path"), 0, 2));
        throws(fn () => Media::storeInto(['name' => 'a.jpg', 'tmp_name' => $tmp, 'error' => 0], 'images/_thumbs/1', $root), InvalidArgumentException::class);
        throws(fn () => Media::storeInto(['name' => 'a.jpg', 'tmp_name' => $tmp, 'error' => 0], 'data', $root), InvalidArgumentException::class);
        unlink($tmp);
        Images::$root = null;
    },

    'media: where images are used' => function () {
        $ids = site_fixture();
        Pages::save($ids['about'], page_data(['pl' => ['title' => 'O nas', 'body' => '<p><img src="images/pages/a.jpg"></p>'], 'en' => ['title' => 'About us']],
            ['image' => 'images/pages/b.jpg', 'publish_up' => '2026-01-01T00:00']), 1);
        Pageyard\Settings::set('template', ['logo' => 'images/site/logo.png'] + Pageyard\Settings::TEMPLATE);
        same([
            'images/pages/a.jpg' => ['Page: O nas'],
            'images/pages/b.jpg' => ['Page: O nas', 'Page: About us'],
            'images/site/logo.png' => ['Template settings'],
            'images/pages/c.jpg' => [],
        ], Media::usage(['images/pages/a.jpg', 'images/pages/b.jpg', 'images/site/logo.png', 'images/pages/c.jpg']));
        Router::reset();
    },
];
