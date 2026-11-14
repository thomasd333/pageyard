<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/** URL helpers. The site may live in a subfolder (e.g. http://localhost/CMS/). */
final class Uri
{
    /** Base path of the site with a trailing slash, e.g. "/CMS/". */
    public static function root(): string
    {
        $live = (string) Config::get('live_site', '');
        if ($live !== '') {
            return rtrim((string) parse_url($live, PHP_URL_PATH), '/') . '/';
        }

        // Entry points other than the site's index.php are one folder deeper.
        $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if (PY_CLIENT !== 'site') {
            $path = str_replace('\\', '/', dirname($path));
        }
        return rtrim($path, '/') . '/';
    }

    /** Path of a file under the site root, e.g. media('vendor/bootstrap/css/bootstrap.min.css'). */
    public static function media(string $path): string
    {
        return self::root() . 'media/' . ltrim($path, '/');
    }

    public static function admin(string $query = ''): string
    {
        return self::root() . 'administrator/' . ($query !== '' ? 'index.php?' . ltrim($query, '?') : '');
    }

    /**
     * Absolute form of a root-relative URL ("/CMS/o-nas" -> "https://example.com/CMS/o-nas"):
     * scheme and host from live_site when set, else from the request. Absolute URLs pass through.
     */
    public static function absolute(string $url): string
    {
        if ($url === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            return $url;
        }
        $live = (string) Config::get('live_site', '');
        if ($live !== '') {
            $origin = parse_url($live, PHP_URL_SCHEME) . '://' . parse_url($live, PHP_URL_HOST)
                . (parse_url($live, PHP_URL_PORT) ? ':' . parse_url($live, PHP_URL_PORT) : '');
        } else {
            $origin = (Request::isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $origin . (str_starts_with($url, '/') ? $url : self::root() . $url);
    }

    public static function redirect(string $url, int $code = 303): never
    {
        header('Location: ' . $url, true, $code);
        exit;
    }
}
