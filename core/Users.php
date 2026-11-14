<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Users: administrators (back end) and registered users (site only). Listing, validation and changes,
 * with the "keep one active administrator" rules.
 */
final class Users
{
    public const MIN_PASSWORD = 8;

    public const SORTABLE = ['name', 'username', 'email', 'active', 'admin', 'last_login', 'id'];

    public static function find(int $id): ?array
    {
        return Db::get()->fetchRow('SELECT * FROM #__users WHERE id = ?', [$id]);
    }

    /**
     * @param array{search: string, status: string, kind?: string, sort: string, dir: string} $state
     *        status: 1 active, 0 blocked, pending (waiting for a confirmation or an approval);
     *        kind: admin | registered
     * @return array{items: list<array>, total: int}
     */
    public static function list(array $state, int $limit, int $offset): array
    {
        $db = Db::get();
        $where = [];
        $params = [];
        if ($state['search'] !== '') {
            if (preg_match('/^id:(\d+)$/', $state['search'], $m)) {
                $where[] = 'id = ?';
                $params[] = (int) $m[1];
            } else {
                $where[] = "(LOWER(name) LIKE ? ESCAPE '!' OR username LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!')";
                $like = '%' . Db::escapeLike(mb_strtolower($state['search'])) . '%';
                array_push($params, $like, $like, $like);
            }
        }
        if ($state['status'] === '1' || $state['status'] === '0') {
            $where[] = 'active = ?';
            $params[] = (int) $state['status'];
        } elseif ($state['status'] === 'pending') {
            $where[] = "pending <> ''";
        }
        $kind = $state['kind'] ?? '';
        if ($kind === 'admin' || $kind === 'registered') {
            $where[] = 'admin = ?';
            $params[] = $kind === 'admin' ? 1 : 0;
        }
        $sql = ' FROM #__users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $sort = in_array($state['sort'], self::SORTABLE, true) ? $state['sort'] : 'name';
        $dir = $state['dir'] === 'desc' ? 'DESC' : 'ASC';

        return [
            'total' => (int) $db->fetchValue('SELECT COUNT(*)' . $sql, $params),
            'items' => $db->fetchAll("SELECT id, name, username, email, active, admin, pending, last_login, created$sql ORDER BY $sort $dir, id"
                . ($limit > 0 ? " LIMIT $limit OFFSET $offset" : ''), $params),
        ];
    }

    /**
     * Checks the account fields (name, username, e-mail, password) and normalises them.
     * The password may stay empty for an existing user ($id > 0): it is kept then.
     *
     * @return array{array, array<string, string>} [data, errors keyed by field]
     */
    public static function validate(int $id, array $data): array
    {
        $db = Db::get();
        $data['username'] = strtolower(trim($data['username']));
        $data['email'] = strtolower(trim($data['email']));
        $data['name'] = trim($data['name']);

        $err = [];
        if ($data['name'] === '') {
            $err['name'] = t('ERR_REQUIRED');
        } elseif (mb_strlen($data['name']) > 100) {
            $err['name'] = t('ERR_TOO_LONG', 100);
        }
        if (!preg_match('/^[a-z0-9._-]{2,150}$/', $data['username'])) {
            $err['username'] = t('USERS_ERR_USERNAME');
        } elseif ($db->fetchValue('SELECT id FROM #__users WHERE username = ? AND id <> ?', [$data['username'], $id])) {
            $err['username'] = t('USERS_ERR_USERNAME_TAKEN');
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 255) {
            $err['email'] = t('USERS_ERR_EMAIL');
        } elseif (self::emailTaken($data['email'], $id)) {
            $err['email'] = t('USERS_ERR_EMAIL_TAKEN');
        }
        if ($id === 0 || $data['password'] !== '') {
            $passwordError = self::passwordError($data['password'], $data['password2']);
            if ($passwordError !== null) {
                $err[$passwordError[0]] = $passwordError[1];
            }
        }
        return [$data, $err];
    }

    /** @return ?array{string, string} [field, message] when a new password can't be used, else null */
    public static function passwordError(string $password, string $password2): ?array
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return ['password', t('USERS_ERR_PASSWORD')];
        }
        if (mb_strlen($password) > 200) {
            return ['password', t('ERR_TOO_LONG', 200)];
        }
        if ($password !== $password2) {
            return ['password2', t('USERS_ERR_PASSWORD_MATCH')];
        }
        return null;
    }

    public static function emailTaken(string $email, int $exceptId = 0): bool
    {
        return (bool) Db::get()->fetchValue('SELECT id FROM #__users WHERE email = ? AND id <> ?', [strtolower(trim($email)), $exceptId]);
    }

    /**
     * Validates and saves a user (new when $id is 0). Returns [id, errors]; errors are keyed by field.
     *
     * @param array{name: string, username: string, email: string, password: string, password2: string, active: bool,
     *              admin?: bool, language: string} $data admin left out: the stored flag stays (a new user: none)
     * @return array{int, array<string, string>}
     */
    public static function save(int $id, array $data, int $currentUserId): array
    {
        $db = Db::get();
        [$data, $err] = self::validate($id, $data);
        if ($data['language'] !== '' && !Lang::exists($data['language'])) {
            $data['language'] = '';
        }
        $stored = $id ? self::find($id) : null;
        $admin = (bool) ($data['admin'] ?? $stored['admin'] ?? false);
        if ($id !== 0 && $id === $currentUserId && !$admin) {
            $err['admin'] = t('USERS_ERR_ADMIN_SELF');
        } elseif ($id !== 0 && $id === $currentUserId && !$data['active']) {
            $err['active'] = t('USERS_ERR_BLOCK_SELF');
        } elseif ($id !== 0 && ($stored['admin'] ?? false) && (!$data['active'] || !$admin) && !self::othersActive([$id])) {
            $err[$data['active'] ? 'admin' : 'active'] = t('USERS_ERR_LAST_ACTIVE');
        }
        if ($err) {
            return [$id, $err];
        }

        $row = [
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'],
            'active'   => $data['active'] ? 1 : 0,
            'admin'    => $admin ? 1 : 0,
            'language' => $data['language'],
            'modified' => Db::now(),
        ];
        if ($data['password'] !== '') {
            $row['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if ($id === 0) {
            $row['created'] = $row['modified'];
            $id = $db->insert('#__users', $row);
            Log::info('User created: ' . $row['username'] . ($admin ? ' (administrator)' : ''));
        } else {
            $db->update('#__users', $row, ['id' => $id]);
            if (isset($row['password'])) {
                $db->delete('#__password_resets', ['user_id' => $id]);
            }
            if ($row['email'] !== $stored['email']) {
                // A confirmation of an address change asked for earlier would undo this one.
                $db->delete('#__user_tokens', ['user_id' => $id, 'purpose' => 'email']);
            }
        }
        return [$id, []];
    }

    /**
     * Sets a password, ends other sessions of this user (see Auth::user), cancels an open
     * reset link and clears failed login attempts.
     */
    public static function setPassword(int $id, string $password): void
    {
        $db = Db::get();
        $user = $db->fetchRow('SELECT username, email FROM #__users WHERE id = ?', [$id]);
        $db->update('#__users', ['password' => password_hash($password, PASSWORD_DEFAULT), 'modified' => Db::now()], ['id' => $id]);
        $db->delete('#__password_resets', ['user_id' => $id]);
        if ($user !== null) {
            $db->query('DELETE FROM #__login_attempts WHERE username = ? OR username = ?', [$user['username'], $user['email']]);
        }
    }

    /**
     * Activates or blocks users. Activating also ends any waiting (for a confirmation or an approval);
     * users who waited for an administrator are told by e-mail. Returns [changed count, error message or ''].
     * @param list<int> $ids
     */
    public static function setActive(array $ids, bool $active, int $currentUserId): array
    {
        if (!$active) {
            if (in_array($currentUserId, $ids, true)) {
                return [0, t('USERS_ERR_BLOCK_SELF')];
            }
            if (!self::othersActive($ids)) {
                return [0, t('USERS_ERR_LAST_ACTIVE')];
            }
        }
        $db = Db::get();
        $count = 0;
        foreach ($ids as $id) {
            $user = self::find($id);
            if ($user === null) {
                continue;
            }
            $row = ['active' => (int) $active, 'modified' => Db::now()];
            if ($active) {
                $row['pending'] = '';
            }
            $count += $db->update('#__users', $row, ['id' => $id]);
            if ($active && $user['pending'] !== '') {
                $db->delete('#__user_tokens', ['user_id' => $id, 'purpose' => 'activate']);
                Log::info("Account approved: {$user['username']}");
                if ($user['pending'] === 'admin') {
                    Accounts::notifyApproved($user);
                }
            }
        }
        return [$count, ''];
    }

    /**
     * Deletes users. Returns [deleted count, error message or ''].
     * @param list<int> $ids
     */
    public static function delete(array $ids, int $currentUserId): array
    {
        if (in_array($currentUserId, $ids, true)) {
            return [0, t('USERS_ERR_DELETE_SELF')];
        }
        if (!self::othersActive($ids)) {
            return [0, t('USERS_ERR_LAST_ACTIVE')];
        }
        $count = 0;
        foreach ($ids as $id) {
            $count += self::remove($id);
        }
        Log::info("Users deleted: $count");
        return [$count, ''];
    }

    /** Deletes one user with the links waiting for them. Returns 1 or 0. */
    public static function remove(int $id): int
    {
        return Db::get()->transaction(function (Db $db) use ($id): int {
            $db->delete('#__user_tokens', ['user_id' => $id]);
            $db->delete('#__password_resets', ['user_id' => $id]);
            return $db->delete('#__users', ['id' => $id]);
        });
    }

    /** True when at least one active administrator remains outside $ids. */
    private static function othersActive(array $ids): bool
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return (int) Db::get()->fetchValue("SELECT COUNT(*) FROM #__users WHERE active = 1 AND admin = 1 AND pending = '' AND id NOT IN ($marks)", $ids) > 0;
    }
}
