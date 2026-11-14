<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * The site's home page: one page or one category (its list), kept in #__settings as
 * {"type": "page"|"category", "id": N}. It lives at the language root (/ and /<lang>/).
 */
final class Home
{
    public const TYPES = ['page', 'category'];

    /** @return array{type: string, id: int}|null the home target if set and it still exists */
    public static function get(): ?array
    {
        $home = Settings::get('home');
        if (!is_array($home) || !in_array($home['type'] ?? '', self::TYPES, true) || (int) ($home['id'] ?? 0) <= 0) {
            return null;
        }
        $table = $home['type'] === 'page' ? '#__pages' : '#__categories';
        if (!Db::get()->fetchValue("SELECT 1 FROM $table WHERE id = ?", [(int) $home['id']])) {
            return null;
        }
        return ['type' => $home['type'], 'id' => (int) $home['id']];
    }

    public static function is(string $type, int $id): bool
    {
        $home = self::get();
        return $home !== null && $home['type'] === $type && $home['id'] === $id;
    }

    /** Makes a page or category the home page and publishes it. False if it doesn't exist. */
    public static function set(string $type, int $id): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            return false;
        }
        $table = $type === 'page' ? '#__pages' : '#__categories';
        if (!Db::get()->fetchValue("SELECT 1 FROM $table WHERE id = ?", [$id])) {
            return false;
        }
        Db::get()->update($table, ['state' => 1, 'access' => Access::ALL, 'modified' => Db::now()], ['id' => $id]);
        Settings::set('home', ['type' => $type, 'id' => $id]);
        return true;
    }

    /**
     * Ids from $ids without the home item of $type (it stays published and can't be deleted).
     * @param list<int> $ids
     * @return list<int>
     */
    public static function without(string $type, array $ids): array
    {
        $home = self::get();
        if ($home === null || $home['type'] !== $type) {
            return $ids;
        }
        return array_values(array_filter($ids, fn ($id) => $id !== $home['id']));
    }
}
