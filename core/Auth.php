<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Logging in. Administrators use the back end (session cookie Session::ADMIN); every active user,
 * administrator or not, may log in on the site (Session::SITE). The two are separate sessions, and a
 * site session is marked as such, so it never opens the back end. There are no permission levels.
 */
final class Auth
{
    /** Failed attempts allowed per username / per IP within WINDOW minutes. */
    public const MAX_PER_USER = 5;
    public const MAX_PER_IP = 20;
    public const WINDOW = 15;

    private const PENDING = ['email' => 'LOGIN_PENDING_EMAIL', 'admin' => 'LOGIN_PENDING_ADMIN'];

    private static ?array $user = null;
    private static ?array $member = null;

    /**
     * The administrator of this back-end session (row from #__users) or null. Ends sessions that expired
     * or whose user was blocked or lost the administrator flag.
     */
    public static function user(): ?array
    {
        return self::$user ??= self::fromSession(false);
    }

    /** The user logged in on the site (any active account) or null. */
    public static function member(): ?array
    {
        return self::$member ??= self::fromSession(true);
    }

    private static function fromSession(bool $site): ?array
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id === 0) {
            return null;
        }
        if ((bool) ($_SESSION['site'] ?? false) !== $site) {
            return null;  // the other kind of session
        }
        $minutes = $site ? Config::get('site_session_lifetime', 1440) : Config::get('session_lifetime', 60);
        if (time() - (int) ($_SESSION['last_seen'] ?? 0) > max(1, (int) $minutes) * 60) {
            self::logout();
            Session::flash(t('LOGIN_EXPIRED'), 'warning');
            return null;
        }
        $user = Db::get()->fetchRow("SELECT * FROM #__users WHERE id = ? AND active = 1 AND pending = ''"
            . ($site ? '' : ' AND admin = 1'), [$id]);
        if ($user === null) {
            self::logout();
            return null;
        }
        // The password changed since this session started: it ends here too.
        $fingerprint = self::fingerprint($user['password']);
        if (!hash_equals($_SESSION['password'] ??= $fingerprint, $fingerprint)) {
            self::logout();
            Session::flash(t('LOGIN_EXPIRED'), 'warning');
            return null;
        }
        $_SESSION['last_seen'] = time();
        return $user;
    }

    /** After users change their own password, this session stays valid. */
    public static function passwordChanged(): void
    {
        $hash = Db::get()->fetchValue('SELECT password FROM #__users WHERE id = ?', [(int) ($_SESSION['user_id'] ?? 0)]);
        if ($hash !== null) {
            $_SESSION['password'] = self::fingerprint((string) $hash);
        }
    }

    private static function fingerprint(string $hash): string
    {
        return hash_hmac('sha256', $hash, (string) Config::get('secret', ''));
    }

    /** Id of the logged-in administrator, 0 without one. */
    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    /**
     * Checks the credentials and logs the user in: into the back end, or with $site into the site.
     * Returns null on success, otherwise a user-facing error message. Why a known account can't log in
     * (blocked, waiting, not an administrator) is only said when the password was right.
     */
    public static function login(string $username, string $password, bool $site = false): ?string
    {
        $db = Db::get();
        $username = strtolower(trim($username));
        $ip = Request::ip();
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW * 60);

        $db->query('DELETE FROM #__login_attempts WHERE created < ?', [$since]);
        $byUser = (int) $db->fetchValue('SELECT COUNT(*) FROM #__login_attempts WHERE username = ?', [$username]);
        $byIp = (int) $db->fetchValue('SELECT COUNT(*) FROM #__login_attempts WHERE ip = ?', [$ip]);
        if ($byUser >= self::MAX_PER_USER || $byIp >= self::MAX_PER_IP) {
            return t('LOGIN_TOO_MANY', self::WINDOW);
        }

        // Accept the e-mail address as well as the username.
        $user = $db->fetchRow('SELECT * FROM #__users WHERE username = ? OR email = ?', [$username, $username]);
        $ok = $user !== null && password_verify($password, $user['password']);
        if (!$ok || !$user['active']) {
            if (!$ok) {
                $db->insert('#__login_attempts', ['ip' => $ip, 'username' => $username, 'created' => Db::now()]);
                usleep(random_int(300_000, 700_000));
            }
            return t($ok ? 'LOGIN_BLOCKED' : 'LOGIN_FAILED');
        }
        if ($user['pending'] !== '') {
            return t(self::PENDING[$user['pending']] ?? 'LOGIN_BLOCKED');
        }
        if (!$site && !$user['admin']) {
            return t('LOGIN_NOT_ADMIN');
        }

        $update = ['last_login' => Db::now()];
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $update['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $db->update('#__users', $update, ['id' => $user['id']]);
        $db->delete('#__login_attempts', ['username' => $username]);

        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_seen'] = time();
        $_SESSION['password'] = self::fingerprint($update['password'] ?? $user['password']);
        if ($site) {
            $_SESSION['site'] = true;
        }
        self::$user = self::$member = null;
        Log::info('Login' . ($site ? ' (site)' : '') . ": $username from $ip");
        return null;
    }

    public static function logout(): void
    {
        self::$user = self::$member = null;
        Session::destroy();
    }
}
