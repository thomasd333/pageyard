<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/** PHP session with safe cookie settings, a CSRF token and flash messages. */
final class Session
{
    /** Session cookie of the administrator. */
    public const ADMIN = 'pageyard_admin';
    /** Session cookie of a user logged in on the site (see Auth::member). */
    public const SITE = 'pageyard_site';

    public static function start(string $name, string $path): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = PY_ROOT . '/data/sessions';
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            session_save_path($dir);
        }
        ini_set('session.use_strict_mode', '1');
        // Both kinds of session share the folder, so clean-up must spare the longer-lived one.
        $minutes = max((int) Config::get('session_lifetime', 60), (int) Config::get('site_session_lifetime', 1440));
        ini_set('session.gc_maxlifetime', (string) max(1440, $minutes * 60));
        session_name($name);
        // After another session of this request was closed (the administrator's, checked on the site),
        // PHP would carry its id over to this one: take this cookie's id instead, or a new one.
        if (session_id() !== '') {
            $id = $_COOKIE[$name] ?? null;
            session_id(is_string($id) && preg_match('/^[a-zA-Z0-9,-]{22,256}$/', $id) ? $id : session_create_id());
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $path,
            'secure'   => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** New session id (after login), keeping the data. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['token']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function token(): string
    {
        return $_SESSION['token'] ??= bin2hex(random_bytes(16));
    }

    public static function checkToken(): bool
    {
        return isset($_SESSION['token']) && hash_equals($_SESSION['token'], Request::str('token'));
    }

    /** @param 'success'|'info'|'warning'|'danger' $type */
    public static function flash(string $text, string $type = 'success'): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'text' => $text];
    }

    /** @return list<array{type: string, text: string}> messages, removed from the session */
    public static function takeFlash(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }
}
