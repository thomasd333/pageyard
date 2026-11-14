<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/**
 * configuration.php in the site root, Joomla style: a class with public properties.
 * The installer creates it; later the Global Configuration screen rewrites it.
 */
final class Config
{
    /** Every option with its default, in the order written to the file. */
    public const DEFAULTS = [
        // Site
        'sitename'       => 'Pageyard',
        'offline'        => false,
        'language'       => 'en',       // default content language (no URL prefix)
        'languages'      => 'en',       // enabled content languages, comma-separated
        'admin_language' => 'en',
        'timezone'       => 'UTC',
        'live_site'      => '',         // empty = detect from the request
        'sef_rewrite'    => true,       // URLs without index.php (needs mod_rewrite / URL Rewrite)
        'robots'         => 'index',    // index | noindex (the whole site hidden from search engines)
        'sitename_title' => 'after',    // site name in page titles: after | before | none
        'blog_text'      => 'full',     // blog lists, pages without a "Read more" line: full | excerpt
        // Database
        'dbtype'         => 'sqlite',   // sqlite | mysql
        'db_path'        => '',         // SQLite file, relative to the site root
        'host'           => 'localhost',
        'port'           => 3306,
        'user'           => '',
        'password'       => '',
        'db'             => '',
        'dbprefix'       => 'py_',
        // System
        'debug'          => false,
        'session_lifetime' => 60,       // minutes of inactivity before the administrator logs out
        'image_max'      => 2000,       // uploaded images are shrunk to this many pixels (longer side; 0 = keep)
        'image_quality'  => 85,         // JPEG/WebP quality of shrunk images and thumbnails
        'image_webp'     => false,      // thumbnails as WebP (smaller files); originals stay as they are
        'backup_interval' => 24,        // hours between automatic SQLite backups (0 = off)
        'backup_keep'    => 10,         // automatic backups kept
        'history_enabled' => true,      // false: no new versions; those already kept expire as usual
        'history_days'   => 7,          // days earlier versions are kept after being replaced
        'history_interval' => 10,       // frequent saves leave a version at most every N minutes (0 = every save)
        // Users
        'site_accounts'  => false,      // users log in on the site (profile, log out, password reset)
        'registration'   => false,      // visitors create accounts themselves (needs site_accounts and e-mail)
        'registration_activation' => 'email',  // email (owner confirms) | admin (approval) | both
        'registration_notify' => false, // e-mail administrators about new accounts (always with approval)
        'privacy_page'   => 0,          // page linked from the registration consent box; 0 = no box
        'site_session_lifetime' => 1440, // minutes of inactivity before a site user logs out
        // E-mail
        'mail_transport' => 'off',      // off | mail | smtp
        'mail_from'      => '',
        'mail_from_name' => '',
        'contact_email'  => '',         // contact form recipient; empty = mail_from
        'smtp_host'      => '',
        'smtp_port'      => 587,
        'smtp_secure'    => 'tls',      // none | tls (STARTTLS) | ssl
        'smtp_user'      => '',
        'smtp_password'  => '',
        'secret'         => '',
    ];

    private static ?array $values = null;

    public static function file(): string
    {
        return PY_ROOT . '/configuration.php';
    }

    public static function exists(): bool
    {
        return is_file(self::file());
    }

    public static function isLoaded(): bool
    {
        return self::$values !== null;
    }

    public static function load(?string $file = null): void
    {
        $file ??= self::file();
        if (!class_exists('PageyardConfig', false)) {
            require $file;
        }
        self::$values = array_merge(self::DEFAULTS, get_object_vars(new \PageyardConfig()));
    }

    /** Uses the given values instead of a file (installer, tests). */
    public static function set(array $values): void
    {
        self::$values = array_merge(self::DEFAULTS, $values);
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        return self::$values[$name] ?? $default;
    }

    public static function all(): array
    {
        return self::$values ?? self::DEFAULTS;
    }

    /** Enabled content languages, default first. */
    public static function languages(): array
    {
        $default = (string) self::get('language', 'en');
        $list = array_filter(array_map('trim', explode(',', (string) self::get('languages', ''))));
        return array_values(array_unique([$default, ...$list]));
    }

    /** Writes configuration.php atomically (temp file + rename). */
    public static function write(array $values, ?string $file = null): void
    {
        $file ??= self::file();
        $values = array_merge(self::DEFAULTS, array_intersect_key($values, self::DEFAULTS));

        $out = "<?php\n\nclass PageyardConfig\n{\n";
        foreach ($values as $name => $value) {
            settype($value, gettype(self::DEFAULTS[$name]));
            $out .= "    public \$$name = " . var_export($value, true) . ";\n";
        }
        $out .= "}\n";

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $out, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write $tmp. Check that the folder is writable.");
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot replace $file. Check that the file is writable.");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        self::$values = $values;
    }
}
