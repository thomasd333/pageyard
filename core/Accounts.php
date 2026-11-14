<?php

namespace Pageyard;

use Pageyard\Database\Db;
use Pageyard\Site\Account;

defined('PAGEYARD') or exit;

/**
 * Accounts on the site: registration, confirmation links, profile changes and the e-mails around them.
 * The pages themselves are Site\Account.
 *
 * A new account waits (users.pending) for its owner's confirmation ('email'), an administrator's
 * approval ('admin'), or the first and then the second ("both"). Accounts nobody confirmed are
 * deleted after TOKEN_HOURS, so a stranger's address or a username isn't held for ever.
 *
 * Registration says when an e-mail address already has an account (the user's choice, for clarity), and
 * points to the password reset. Links use PasswordReset::origin(), never the request's host.
 */
final class Accounts
{
    public const TOKEN_HOURS = 48;
    public const ACTIVATIONS = ['email', 'admin', 'both'];
    public const REGISTER_PER_HOUR = 5;

    /** Users may log in on the site. */
    public static function enabled(): bool
    {
        return (bool) Config::get('site_accounts', false);
    }

    /** Visitors may create accounts: switched on, and e-mail works with a known address for links. */
    public static function registrationOpen(): bool
    {
        return self::enabled() && Config::get('registration', false) && PasswordReset::available();
    }

    public static function activation(): string
    {
        $mode = (string) Config::get('registration_activation', 'email');
        return in_array($mode, self::ACTIVATIONS, true) ? $mode : 'email';
    }

    /** Page linked from the consent box, 0 = no box. */
    public static function privacyPage(): int
    {
        return max(0, (int) Config::get('privacy_page', 0));
    }

    /**
     * Creates an account from the registration form. Returns errors keyed by field ('' = about the
     * whole form); empty when done.
     *
     * @param array{name: string, username: string, email: string, password: string, password2: string, consent: bool} $data
     */
    public static function register(array $data, string $lang): array
    {
        self::purge();
        [$data, $err] = Users::validate(0, $data);
        if (isset($err['email']) && Users::emailTaken($data['email'])) {
            $err['email'] = t('ACCOUNT_ERR_EMAIL_TAKEN');
        }
        if (self::privacyPage() > 0 && !$data['consent']) {
            $err['consent'] = t('ACCOUNT_ERR_CONSENT');
        }
        if ($err) {
            return $err;
        }
        if (!RateLimit::hit('register', Request::ip(), self::REGISTER_PER_HOUR, 60)) {
            return ['' => t('ACCOUNT_ERR_LIMIT')];
        }
        $site = (string) Config::get('sitename');
        $mode = self::activation();
        $id = Db::get()->transaction(function (Db $db) use ($data, $mode, $lang): int {
            $now = Db::now();
            return $db->insert('#__users', [
                'name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'],
                'password' => password_hash($data['password'], PASSWORD_DEFAULT), 'active' => 1, 'admin' => 0,
                'pending' => $mode === 'admin' ? 'admin' : 'email', 'language' => Lang::exists($lang) ? $lang : '',
                'created' => $now, 'modified' => $now,
            ]);
        });
        $user = Users::find($id);
        Log::info("Registration: {$data['username']} from " . Request::ip());
        if ($mode === 'admin') {
            self::notifyAdmins($user, true);
        } else {
            $token = self::token($id, 'activate');
            Mailer::send($data['email'], t('ACCOUNT_MAIL_ACTIVATE_SUBJECT', $site),
                t('ACCOUNT_MAIL_ACTIVATE_BODY', $site, $data['username'], self::origin() . Account::url('confirm', $lang) . "?token=$token", self::TOKEN_HOURS));
        }
        return [];
    }

    /**
     * Follows a link from an e-mail. Returns what happened: 'confirmed' (the account works now),
     * 'approval' (it waits for an administrator), 'email' (address changed), 'taken' (the new address
     * got an account in the meantime) or null (unknown or expired link).
     */
    public static function confirm(string $token): ?string
    {
        self::purge();
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $db = Db::get();
        $row = $db->fetchRow('SELECT * FROM #__user_tokens WHERE token_hash = ? AND created >= ?',
            [hash('sha256', $token), gmdate('Y-m-d H:i:s', time() - self::TOKEN_HOURS * 3600)]);
        $user = $row !== null ? Users::find((int) $row['user_id']) : null;
        if ($user === null) {
            return null;
        }
        $db->delete('#__user_tokens', ['user_id' => $user['id'], 'purpose' => $row['purpose']]);

        if ($row['purpose'] === 'email') {
            $email = (string) $row['data'];
            if (Users::emailTaken($email, (int) $user['id'])) {
                return 'taken';
            }
            $db->update('#__users', ['email' => $email, 'modified' => Db::now()], ['id' => $user['id']]);
            $db->delete('#__password_resets', ['user_id' => $user['id']]);
            Log::info("E-mail address changed: {$user['username']}");
            return 'email';
        }
        if ($row['purpose'] !== 'activate' || $user['pending'] !== 'email') {
            return null;
        }
        $next = self::activation() === 'both' ? 'admin' : '';
        $db->update('#__users', ['pending' => $next, 'modified' => Db::now()], ['id' => $user['id']]);
        Log::info("Account confirmed: {$user['username']}");
        if ($next === 'admin' || Config::get('registration_notify', false)) {
            self::notifyAdmins(['pending' => $next] + $user, $next === 'admin');
        }
        return $next === 'admin' ? 'approval' : 'confirmed';
    }

    /**
     * Changes from the profile form. Changing the e-mail or the password needs the current password;
     * a new address only applies after its owner follows the link sent there.
     *
     * @param array{name: string, email: string, current: string, password: string, password2: string} $data
     * @return array{array<string, string>, bool} [errors keyed by field, a confirmation link was sent]
     */
    public static function updateProfile(array $user, array $data, string $lang): array
    {
        $err = [];
        $name = trim($data['name']);
        $email = strtolower(trim($data['email']));
        if ($name === '') {
            $err['name'] = t('ERR_REQUIRED');
        } elseif (mb_strlen($name) > 100) {
            $err['name'] = t('ERR_TOO_LONG', 100);
        }
        $newEmail = $email !== $user['email'];
        if ($newEmail && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255)) {
            $err['email'] = t('USERS_ERR_EMAIL');
        } elseif ($newEmail && !PasswordReset::available()) {
            $err['email'] = t('ACCOUNT_ERR_EMAIL_NO_MAIL');
        }
        $newPassword = $data['password'] !== '' || $data['password2'] !== '';
        if ($newPassword) {
            $passwordError = Users::passwordError($data['password'], $data['password2']);
            if ($passwordError !== null) {
                $err[$passwordError[0]] = $passwordError[1];
            }
        }
        if (($newEmail || $newPassword) && !password_verify($data['current'], $user['password'])) {
            $err['current'] = t($data['current'] === '' ? 'ACCOUNT_ERR_CURRENT_NEEDED' : 'ACCOUNT_ERR_CURRENT');
        }
        if ($err) {
            return [$err, false];
        }

        $db = Db::get();
        $id = (int) $user['id'];
        if ($name !== $user['name']) {
            $db->update('#__users', ['name' => $name, 'modified' => Db::now()], ['id' => $id]);
        }
        if ($newPassword) {
            Users::setPassword($id, $data['password']);
            Log::info("Password changed on the site: {$user['username']}");
        }
        if ($newEmail) {
            // An address someone else uses gets no link; the page says the same either way.
            if (!Users::emailTaken($email, $id) && RateLimit::hit('email_change', (string) $id, 3, 60)) {
                $token = self::token($id, 'email', $email);
                $site = (string) Config::get('sitename');
                Mailer::send($email, t('ACCOUNT_MAIL_EMAIL_SUBJECT', $site),
                    t('ACCOUNT_MAIL_EMAIL_BODY', $site, $user['username'], self::origin() . Account::url('confirm', $lang) . "?token=$token", self::TOKEN_HOURS));
            }
        }
        return [[], $newEmail];
    }

    /**
     * Deletes one's own account after checking the password. Administrators' accounts are managed in
     * the back end. Returns an error message or null when done.
     */
    public static function deleteOwn(array $user, string $password): ?string
    {
        if ($user['admin']) {
            return t('ACCOUNT_ERR_DELETE_ADMIN');
        }
        if (!password_verify($password, $user['password'])) {
            return t('ACCOUNT_ERR_CURRENT');
        }
        Users::remove((int) $user['id']);
        Log::info("Account deleted by its owner: {$user['username']}");
        return null;
    }

    /** Deletes accounts whose owners never confirmed them in time. */
    public static function purge(): int
    {
        $db = Db::get();
        $before = gmdate('Y-m-d H:i:s', time() - self::TOKEN_HOURS * 3600);
        $db->query('DELETE FROM #__user_tokens WHERE created < ?', [$before]);
        $count = 0;
        foreach ($db->fetchAll("SELECT id, username FROM #__users WHERE pending = 'email' AND admin = 0 AND last_login IS NULL AND created < ?", [$before]) as $user) {
            $count += Users::remove((int) $user['id']);
            Log::info("Unconfirmed account removed: {$user['username']}");
        }
        return $count;
    }

    /** Tells a user waiting for approval that the account works now. */
    public static function notifyApproved(array $user): void
    {
        $origin = PasswordReset::origin();
        if ($origin === null) {
            return;
        }
        $language = Lang::current();
        $lang = $user['language'] !== '' && in_array($user['language'], Config::languages(), true) ? $user['language'] : (string) Config::get('language');
        Lang::set($lang);
        $site = (string) Config::get('sitename');
        Mailer::send($user['email'], t('ACCOUNT_MAIL_APPROVED_SUBJECT', $site),
            t('ACCOUNT_MAIL_APPROVED_BODY', $site, $user['username'], $origin . Account::url('login', $lang)));
        Lang::set($language);
    }

    /** E-mails every active administrator about a new account; $approval: it waits for one of them. */
    private static function notifyAdmins(array $user, bool $approval): void
    {
        $origin = PasswordReset::origin();
        $admins = Db::get()->fetchAll("SELECT email, language FROM #__users WHERE admin = 1 AND active = 1 AND pending = ''");
        if ($origin === null) {
            return;
        }
        $language = Lang::current();
        $site = (string) Config::get('sitename');
        $link = $origin . Uri::admin('view=user&id=' . (int) $user['id']);
        foreach ($admins as $admin) {
            Lang::set($admin['language'] !== '' ? $admin['language'] : (string) Config::get('admin_language'));
            Mailer::send($admin['email'], t($approval ? 'ACCOUNT_MAIL_APPROVE_SUBJECT' : 'ACCOUNT_MAIL_NEW_SUBJECT', $site),
                t($approval ? 'ACCOUNT_MAIL_APPROVE_BODY' : 'ACCOUNT_MAIL_NEW_BODY', $site, $user['name'], $user['username'], $user['email'], $link));
        }
        Lang::set($language);
    }

    /** A new one-time link of this kind for the user (an older one stops working). */
    private static function token(int $userId, string $purpose, ?string $data = null): string
    {
        $token = bin2hex(random_bytes(32));
        $db = Db::get();
        $db->delete('#__user_tokens', ['user_id' => $userId, 'purpose' => $purpose]);
        $db->insert('#__user_tokens', ['user_id' => $userId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token),
            'data' => $data, 'created' => Db::now()]);
        return $token;
    }

    private static function origin(): string
    {
        return (string) PasswordReset::origin();
    }
}
