<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * "Forgot password" by e-mail. Only a hash of the token is stored; a token works once, for an hour.
 * From the back end only administrators get a link (to the back end); from the site anyone with an
 * account does (to the site's form).
 *
 * The link's host never comes from the request: a forged Host header would send someone's reset link to
 * another server. It comes from live_site, or from the address an administrator used when saving the
 * e-mail settings (rememberOrigin). Without either the feature is not offered.
 */
final class PasswordReset
{
    public const LIFETIME = 60;  // minutes

    public static function available(): bool
    {
        return Mailer::enabled() && self::origin() !== null;
    }

    /** Called from a logged-in administrator's request. */
    public static function rememberOrigin(): void
    {
        Settings::set('site_origin', (Request::isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    /** Scheme and host for links in e-mails, or null when none is known. */
    public static function origin(): ?string
    {
        $live = (string) Config::get('live_site', '');
        if ($live !== '') {
            return parse_url($live, PHP_URL_SCHEME) . '://' . parse_url($live, PHP_URL_HOST)
                . (parse_url($live, PHP_URL_PORT) ? ':' . parse_url($live, PHP_URL_PORT) : '');
        }
        $stored = Settings::get('site_origin');
        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * Sends a link to the active user with this e-mail or username. Says nothing about whether one
     * exists: the caller shows the same message either way.
     *
     * @param ?string $siteLang the site's form asks, in this language; null = the back end
     */
    public static function request(string $login, ?string $siteLang = null): void
    {
        if (!self::available() || !RateLimit::hit('reset_ip', Request::ip(), 10, 60)) {
            return;
        }
        $db = Db::get();
        $login = strtolower(trim($login));
        $user = $db->fetchRow("SELECT * FROM #__users WHERE (username = ? OR email = ?) AND active = 1 AND pending = ''"
            . ($siteLang === null ? ' AND admin = 1' : ''), [$login, $login]);
        if ($user === null || !RateLimit::hit('reset_user', (string) $user['id'], 3, 60)) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $db->delete('#__password_resets', ['user_id' => $user['id']]);
        $db->insert('#__password_resets', ['user_id' => $user['id'], 'token_hash' => hash('sha256', $token), 'created' => Db::now()]);

        $site = (string) Config::get('sitename');
        $language = Lang::current();
        if ($siteLang === null) {
            $link = self::origin() . Uri::admin("view=reset&token=$token");
            if ($user['language'] !== '') {
                Lang::set($user['language']);
            }
        } else {
            $link = self::origin() . Site\Account::url('reset', $siteLang) . "?token=$token";
        }
        Mailer::send($user['email'], t('RESET_MAIL_SUBJECT', $site),
            t($siteLang === null ? 'RESET_MAIL_BODY' : 'RESET_MAIL_BODY_SITE', $site, $user['username'], $link, self::LIFETIME));
        Lang::set($language);
        Log::info("Password reset requested: {$user['username']} from " . Request::ip());
    }

    /** The user a valid token belongs to, or null. */
    public static function userFor(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        return Db::get()->fetchRow('SELECT u.* FROM #__password_resets r JOIN #__users u ON u.id = r.user_id
            WHERE r.token_hash = ? AND r.created >= ? AND u.active = 1 AND u.pending = \'\'',
            [hash('sha256', $token), gmdate('Y-m-d H:i:s', time() - self::LIFETIME * 60)]);
    }

    /**
     * Sets the new password and uses up the token.
     * @return array<string, string> errors; empty when done
     */
    public static function complete(string $token, string $password, string $password2): array
    {
        $user = self::userFor($token);
        if ($user === null) {
            return ['token' => t('RESET_INVALID')];
        }
        if (mb_strlen($password) < Users::MIN_PASSWORD) {
            return ['password' => t('USERS_ERR_PASSWORD')];
        }
        if ($password !== $password2) {
            return ['password2' => t('USERS_ERR_PASSWORD_MATCH')];
        }
        Users::setPassword((int) $user['id'], $password);
        Log::info("Password reset done: {$user['username']} from " . Request::ip());
        return [];
    }
}
